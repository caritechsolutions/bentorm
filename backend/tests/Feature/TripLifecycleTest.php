<?php

namespace Tests\Feature;

use App\Enums\OfferResponse;
use App\Enums\TripStatus;
use App\Jobs\DispatchTripJob;
use App\Jobs\SendTripReceiptJob;
use App\Models\Trip;
use App\Models\TripOffer;
use App\Models\User;
use App\Services\DriverLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class TripLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $bb = $this->seedReference();

        // Redis is not used in tests — stub the live-location service.
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });

        $this->rider = $this->makeUser('rider');
        $this->driver = $this->makeApprovedDriver($bb);
    }

    private function requestTrip(array $extra = []): Trip
    {
        $res = $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, $extra));
        $res->assertCreated()->assertJsonPath('status', 'requested');
        Queue::assertPushed(DispatchTripJob::class);

        return Trip::query()->where('public_id', $res->json('id'))->firstOrFail();
    }

    private function offerTo(Trip $trip, User $driver): TripOffer
    {
        return TripOffer::query()->create([
            'trip_id' => $trip->id, 'driver_id' => $driver->id, 'distance_m' => 500,
            'offered_at' => now(), 'expires_at' => now()->addSeconds(20),
        ]);
    }

    public function test_full_happy_path_with_money_split(): void
    {
        $trip = $this->requestTrip();
        $this->assertNotNull($trip->service_area_id);
        $this->offerTo($trip, $this->driver);

        $d = $this->actingAs($this->driver);
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk()->assertJsonPath('status', 'accepted');
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk()->assertJsonPath('status', 'arrived');
        $this->travel(6)->minutes(); // 3 minutes beyond the free wait
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/start", ['pin' => $trip->fresh()->pin])->assertOk()->assertJsonPath('status', 'in_progress');

        $this->travel(15)->minutes();
        $res = $d->postJson("/api/v1/driver/trips/{$trip->public_id}/complete", ['lat' => self::OISTINS[0], 'lng' => self::OISTINS[1]]);
        $res->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('payment_status', 'paid');

        $t = $trip->fresh();
        $this->assertGreaterThan(0, $t->final_fare);
        $this->assertEqualsWithDelta($t->final_fare, $t->subtotal + $t->tax_amount, 0.001);
        $this->assertEqualsWithDelta($t->subtotal, $t->platform_fee + $t->driver_earnings, 0.001);
        $this->assertContains('waiting', array_column($t->fare_breakdown['lines'], 'code'));
        $this->assertSame((float) $res->json('driver_earnings'), (float) $t->driver_earnings);
        Queue::assertPushed(SendTripReceiptJob::class);

        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/rate", ['score' => 5])->assertCreated();
        $this->assertSame(1, $this->driver->driverProfile->fresh()->rating_count);
        $this->assertSame(1, $this->driver->driverProfile->fresh()->completed_trips);
    }

    public function test_second_driver_cannot_accept_taken_trip(): void
    {
        $trip = $this->requestTrip();
        $other = $this->makeApprovedDriver();
        $this->offerTo($trip, $this->driver);
        $this->offerTo($trip, $other);

        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
        $this->actingAs($other)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertStatus(409);

        $this->assertSame(OfferResponse::Withdrawn, TripOffer::query()->where('driver_id', $other->id)->first()->response);
    }

    public function test_rider_cancel_after_accept_incurs_fee_and_is_owed(): void
    {
        $trip = $this->requestTrip();
        $this->offerTo($trip, $this->driver);
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();

        $res = $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/cancel", ['reason' => 'Changed plans']);
        // BBD 5.00 + 17.5% VAT
        $res->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelled_by', 'rider')
            ->assertJsonPath('cancellation_fee', 5.88)->assertJsonPath('amount_due', 5.88);

        $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS))
            ->assertStatus(422)->assertJsonValidationErrors('payment');
    }

    public function test_offers_show_estimated_earnings_and_driver_can_see_a_trip_the_rider_cancelled(): void
    {
        $trip = $this->requestTrip();
        $this->offerTo($trip, $this->driver);

        $offer = $this->actingAs($this->driver)->getJson('/api/v1/driver/offers')->assertOk()->json('offers.0');
        // ex VAT (17.5%), after 20% commission
        $this->assertEqualsWithDelta(round($trip->estimated_fare / 1.175 * 0.8, 2), $offer['estimated_earnings'], 0.001);
        $this->assertLessThan($trip->estimated_fare, $offer['estimated_earnings']);

        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/cancel")->assertOk();

        $this->actingAs($this->driver)->getJson('/api/v1/driver/trips/active')->assertOk()->assertJsonPath('trip', null);
        $this->actingAs($this->driver)->getJson("/api/v1/driver/trips/{$trip->public_id}")
            ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelled_by', 'rider')
            ->assertJsonPath('rider.name', $this->rider->name);

        $this->actingAs($this->makeApprovedDriver())->getJson("/api/v1/driver/trips/{$trip->public_id}")->assertNotFound();
    }

    public function test_rider_cancel_before_accept_is_free(): void
    {
        $trip = $this->requestTrip();
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/cancel")
            ->assertOk()->assertJsonPath('cancellation_fee', 0.0)->assertJsonPath('payment_status', 'waived');
    }

    public function test_rider_cannot_have_two_active_trips(): void
    {
        $this->requestTrip();
        $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS))->assertStatus(422);
    }

    public function test_request_outside_service_area_is_rejected(): void
    {
        $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::ST_LUCIA, self::BRIDGETOWN))
            ->assertStatus(422)->assertJsonValidationErrors('pickup');
    }

    public function test_driver_without_documents_cannot_go_online(): void
    {
        $res = $this->actingAs($this->driver)->postJson('/api/v1/driver/online');
        $res->assertForbidden()->assertJsonPath('message', 'You cannot go online yet.');
        $this->assertNotEmpty($res->json('issues'));
    }

    public function test_state_machine_rejects_skipping_steps(): void
    {
        $this->assertFalse(TripStatus::Requested->canTransitionTo(TripStatus::InProgress));
        $this->assertFalse(TripStatus::Completed->canTransitionTo(TripStatus::Cancelled));
        $this->assertTrue(TripStatus::Arrived->canTransitionTo(TripStatus::InProgress));
    }
}
