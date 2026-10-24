<?php

namespace Tests\Feature;

use App\Filament\Pages\NavigationSettings;
use App\Models\AppSetting;
use App\Models\NavigationUsage;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class NavigationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $bb = $this->seedReference();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });
        $this->driver = $this->makeApprovedDriver($bb);
        $this->trip = Trip::query()->create([
            'rider_id' => $this->makeUser()->id, 'driver_id' => $this->driver->id, 'service_area_id' => $bb->id, 'status' => 'accepted',
            'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'dropoff_lat' => 13.07, 'dropoff_lng' => -59.53,
        ]);
    }

    private function ask(string $destination = 'pickup', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->driver)->postJson("/api/v1/driver/trips/{$this->trip->public_id}/navigation", ['destination' => $destination]);
    }

    public function test_off_by_default_and_nothing_is_counted(): void
    {
        $this->getJson('/api/v1/config')->assertJsonPath('navigation.in_app', false);
        $this->ask()->assertOk()->assertJson(['in_app' => false, 'reason' => 'off']);
        $this->assertSame(0, NavigationUsage::query()->count());
    }

    public function test_on_counts_each_destination_and_stops_at_the_monthly_limit(): void
    {
        AppSetting::put('navigation.in_app', true);
        AppSetting::put('navigation.monthly_limit', 2);
        $this->getJson('/api/v1/config')->assertJsonPath('navigation.in_app', true);

        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$this->trip->public_id}/navigation", ['destination' => 'pickup', 'check' => true])
            ->assertJson(['in_app' => true]);
        $this->assertSame(0, NavigationUsage::query()->count(), 'a check is not counted');
        $this->ask('pickup')->assertJson(['in_app' => true, 'reason' => null]);
        $this->ask('dropoff')->assertJson(['in_app' => true]);
        $this->ask('stop')->assertJson(['in_app' => false, 'reason' => 'monthly_limit']);
        $this->assertSame(['pickup', 'dropoff'], NavigationUsage::query()->orderBy('id')->pluck('destination')->all());
        $this->getJson('/api/v1/config')->assertJsonPath('navigation.in_app', false);

        // Next month the count starts again.
        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());
        $this->ask('pickup')->assertJson(['in_app' => true]);
    }

    public function test_only_the_trips_driver_can_ask_and_only_while_active(): void
    {
        AppSetting::put('navigation.in_app', true);
        $this->ask('pickup', $this->makeApprovedDriver())->assertNotFound();
        $this->ask('somewhere')->assertUnprocessable();
        $this->trip->update(['status' => 'completed']);
        $this->ask()->assertNotFound();
        $this->assertSame(0, NavigationUsage::query()->count());
    }

    public function test_admin_page_switches_it_on_and_shows_usage(): void
    {
        NavigationUsage::query()->create(['trip_id' => $this->trip->id, 'driver_id' => $this->driver->id, 'destination' => 'pickup']);
        $this->actingAs($this->makeUser('admin'));
        $this->get('/admin/navigation-settings')->assertOk()->assertSee('This month: 1 destinations')->assertSee('Off — drivers use the Google Maps app.');

        Livewire::test(NavigationSettings::class)
            ->fillForm(['in_app' => true, 'monthly_limit' => 900])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(AppSetting::get('navigation.in_app'));
        $this->assertSame(900, AppSetting::get('navigation.monthly_limit'));
        $this->get('/admin/navigation-settings')->assertSee('This month: 1 destinations of 900')->assertSee('On — drivers navigate inside the app.');

        Livewire::test(NavigationSettings::class)->fillForm(['in_app' => false, 'monthly_limit' => null])->call('save');
        $this->assertFalse(AppSetting::get('navigation.in_app'));
        $this->assertNull(AppSetting::get('navigation.monthly_limit'));
    }
}
