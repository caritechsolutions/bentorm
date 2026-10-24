<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Models\AutoSurgeSetting;
use App\Models\Trip;
use App\Services\AutoSurgeService;
use App\Services\DriverLocationService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class AutoSurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_busy_area_raises_fares_capped_and_falls_back(): void
    {
        Queue::fake();
        $bb = $this->seedReference();
        $driver = $this->makeApprovedDriver($bb);
        $this->mock(DriverLocationService::class, fn (MockInterface $m) => $m->shouldReceive('allLive')->andReturn([['driver_id' => $driver->id, 'state' => 'idle', 'lat' => 13.1, 'lng' => -59.6]]));
        $rider = $this->makeUser();
        for ($i = 0; $i < 4; $i++) {
            Trip::query()->create(['rider_id' => $rider->id, 'service_area_id' => $bb->id, 'vehicle_type' => 'standard', 'status' => TripStatus::NoDrivers,
                'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'dropoff_lat' => 13.07, 'dropoff_lng' => -59.53, 'requested_at' => now()->subMinutes(2)]);
        }
        $estimate = fn () => $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['vehicle_type' => 'standard']))->json('quotes.0');
        $normal = $estimate();

        // Off by default: computed but not applied.
        $this->assertSame([$bb->id => 1.0], app(AutoSurgeService::class)->compute());
        Features::put('auto_surge', true);
        // 4 requests for 1 free driver: ratio 4 → 1 + (4 − 1.5) × 0.25 + 0.1 = 1.725 → ×1.7
        $this->assertSame([$bb->id => 1.7], app(AutoSurgeService::class)->compute());
        $s = AutoSurgeSetting::query()->first();
        $this->assertSame([4, 1], [$s->demand, $s->supply]);
        $busy = $estimate();
        $this->assertGreaterThan($normal['fare'], $busy['fare']);
        $this->assertEquals(1.7, $busy['surge_multiplier']);

        $s->update(['max_multiplier' => 1.3]);
        $this->assertSame([$bb->id => 1.3], app(AutoSurgeService::class)->compute(), 'capped');
        $this->travel(11)->minutes();
        $this->assertSame([$bb->id => 1.0], app(AutoSurgeService::class)->compute(), 'requests outside the window no longer count');

        $this->actingAs($this->makeUser('admin'), 'web')->get('/admin/auto-surge-settings')->assertOk()->assertSee('Free drivers');
    }
}
