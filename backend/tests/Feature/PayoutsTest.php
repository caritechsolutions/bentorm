<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Filament\Pages\Payouts;
use App\Models\LedgerEntry;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\LedgerService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class PayoutsTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });
        $bb = $this->seedReference();
        $this->rider = $this->makeUser('rider');
        $this->driver = $this->makeApprovedDriver($bb);
    }

    private function ride(array $extra = []): Trip
    {
        $id = $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, $extra))->assertSuccessful()->json('id');
        $t = Trip::query()->where('public_id', $id)->first();
        $t->forceFill(['driver_id' => $this->driver->id, 'status' => TripStatus::InProgress, 'started_at' => now()->subMinutes(15), 'accepted_at' => now()->subMinutes(20)])->save();

        return app(TripService::class)->complete($t->fresh(), $this->driver);
    }

    public function test_card_and_cash_rides_tips_and_a_payout_on_the_driver_statement(): void
    {
        $card = $this->addTestCard($this->rider);
        $c = $this->ride(['payment_method' => 'card', 'payment_method_id' => $card]);
        $this->assertEqualsWithDelta($c->driver_earnings, LedgerEntry::balance('driver', $this->driver->id), 0.001, 'card ride: Ride Coop owes the driver their share');
        $this->actingAs($this->rider, 'sanctum')->postJson("/api/v1/trips/{$c->public_id}/tip", ['amount' => 5])->assertOk();

        $cash = $this->ride();
        $owedForCash = round($cash->driver_earnings - $cash->final_fare, 2);
        $this->assertLessThan(0, $owedForCash, 'cash ride: the driver kept the fare, so owes Ride Coop its share');
        $expected = round($c->driver_earnings + 5 + $owedForCash, 2);
        $this->assertEqualsWithDelta($expected, LedgerEntry::balance('driver', $this->driver->id), 0.001);
        app(LedgerService::class)->bookTrip($cash->fresh());
        $this->assertSame(3, LedgerEntry::query()->count(), 'booked once');

        // Statement in the driver app, and bank details.
        $url = $this->actingAs($this->driver, 'sanctum')->postJson('/api/v1/app-links', ['page' => 'statement'])->json('url');
        $this->app['auth']->forgetGuards();
        $this->followingRedirects()->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('Card ride')->assertSee('Tip from')->assertSee('Add your bank account');
        $this->post('/app/statement/bank', ['bank_name' => 'Republic Bank', 'bank_account_name' => 'Dave Driver', 'bank_account_number' => '1234-5678'])->assertRedirect('/app/statement');
        $this->assertSame('1234-5678', $this->driver->driverProfile->fresh()->bank_account_number);
        $this->assertStringNotContainsString('1234-5678', (string) \DB::table('driver_profiles')->where('user_id', $this->driver->id)->value('bank_account_number'), 'stored encrypted');

        // Admin pays the balance and records it: the balance goes to zero.
        $this->actingAs($this->makeUser('admin'), 'web');
        $this->get('/admin/payouts')->assertOk()->assertSee('Republic Bank · Dave Driver · 1234-5678');
        $balance = LedgerEntry::balance('driver', $this->driver->id);
        Livewire::test(Payouts::class)->callAction('record', ['amount' => $balance, 'method' => 'bank_transfer', 'reference' => 'TRX-77'],
            arguments: ['type' => 'driver', 'id' => $this->driver->id, 'balance' => $balance, 'name' => 'Dave'])->assertHasNoActionErrors();
        $this->assertSame(0.0, LedgerEntry::balance('driver', $this->driver->id));
        $this->assertSame('Paid to you · bank transfer · ref TRX-77', LedgerEntry::query()->latest('id')->value('description'));
        $csv = Livewire::test(Payouts::class)->call('csv');
        $this->assertNotNull($csv);
    }

    public function test_promo_on_a_cash_ride_is_owed_back_to_the_driver(): void
    {
        \App\Models\PromoCode::query()->create(['code' => 'FIVE', 'kind' => 'amount', 'value' => 5]);
        $t = $this->ride(['promo_code' => 'five']);
        // The driver collected 5 less in cash, so owes Ride Coop 5 less.
        $this->assertEqualsWithDelta(round($t->driver_earnings - ($t->final_fare - 5), 2), LedgerEntry::balance('driver', $this->driver->id), 0.001);
    }
}
