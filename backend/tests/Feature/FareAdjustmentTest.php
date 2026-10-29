<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Pages\ViewTrip;
use App\Models\CreditEntry;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\FareAdjustmentService;
use App\Services\TripService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/** Admin corrects a finished ride's fare: rider refunded (card, credit or cash) or owes more; driver's share follows. */
class FareAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    private User $driver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });
        $this->seedReference();
        $this->rider = $this->makeUser();
        $this->driver = $this->makeApprovedDriver();
        $this->admin = $this->makeUser('admin');
    }

    private function ride(array $extra = []): Trip
    {
        $id = $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, $extra))->assertSuccessful()->json('id');
        $t = Trip::query()->where('public_id', $id)->first();
        $t->forceFill(['driver_id' => $this->driver->id, 'status' => TripStatus::InProgress, 'started_at' => now()->subMinutes(15), 'accepted_at' => now()->subMinutes(20)])->save();

        return app(TripService::class)->complete($t->fresh(), $this->driver);
    }

    public function test_lower_fare_on_a_card_ride_refunds_part_to_the_card_and_cuts_the_drivers_share(): void
    {
        $trip = $this->ride(['payment_method' => 'card', 'payment_method_id' => $this->addTestCard($this->rider)]);
        $old = (float) $trip->final_fare;
        $oldEarnings = (float) $trip->driver_earnings;
        $this->assertSame('captured', Payment::query()->where('trip_id', $trip->id)->latest('id')->value('status'));

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewTrip::class, ['record' => $trip->public_id])
            ->callAction('adjustFare', ['new_fare' => round($old - 5, 2), 'refund_as' => 'card', 'reason' => 'Driver took a longer route'])
            ->assertHasNoActionErrors();

        $t = $trip->fresh();
        $this->assertEqualsWithDelta($old - 5, $t->final_fare, 0.001);
        $this->assertEqualsWithDelta($oldEarnings * ($old - 5) / $old, $t->driver_earnings, 0.011);
        $this->assertSame('paid', $t->payment_status->value, 'part refund: still paid');
        $this->assertEqualsWithDelta($t->driver_earnings, LedgerEntry::balance('driver', $this->driver->id), 0.011, 'statement follows the new share');
        $this->assertSame(1, DB::table('fare_adjustments')->where('trip_id', $trip->id)->where('refund_as', 'card')->where('refund_amount', 5)->count());
        $this->assertSame('Driver took a longer route', $t->fare_breakdown['adjustment']['reason']);
    }

    public function test_cash_ride_lower_as_credit_and_higher_is_owed(): void
    {
        $trip = $this->ride(['payment_method' => 'cash']);
        $old = (float) $trip->final_fare;
        app(FareAdjustmentService::class)->adjust($trip, $old - 3, 'Wrong pickup', 'credit', $this->admin);
        $this->assertSame(3.0, CreditEntry::balance($this->rider->id));

        $trip2 = $this->ride(['payment_method' => 'cash']);
        app(FareAdjustmentService::class)->adjust($trip2, (float) $trip2->final_fare + 4, 'Toll not added', 'credit', $this->admin);
        $t2 = $trip2->fresh();
        $this->assertSame(['pending', 4.0], [$t2->payment_status->value, (float) $t2->amount_due]);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/outstanding')->assertJsonPath('0.id', $trip2->public_id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(FareAdjustmentService::class)->adjust($trip2->fresh(), (float) $t2->final_fare - 1, 'x', 'card', $this->admin); // no card payment
    }
}
