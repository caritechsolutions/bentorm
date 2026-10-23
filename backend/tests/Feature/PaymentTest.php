<?php

namespace Tests\Feature;

use App\Jobs\DispatchTripJob;
use App\Models\Gateway;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\TripOffer;
use App\Models\User;
use App\Services\DriverLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class PaymentTest extends TestCase
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

    /** Add a card through the hosted test pages, like the app's webview would. */
    private function addCard(string $number = '4242424242424242'): string
    {
        $res = $this->actingAs($this->rider)->postJson('/api/v1/me/payment-methods', ['lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]])->assertCreated();
        $form = $this->get($res->json('redirect_url'))->assertOk()->assertSee('Add a card');
        preg_match('/action="([^"]+)"/', $form->getContent(), $m);
        $this->post(html_entity_decode($m[1]), ['number' => $number, 'exp_month' => 12, 'exp_year' => now()->year + 2])->assertRedirect();
        $this->get(route('payment-methods.return', ['paymentMethod' => $res->json('payment_method.id')]))->assertOk()->assertSee('Card saved');

        return $res->json('payment_method.id');
    }

    private function requestCardRide(?string $pmId = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, array_filter([
            'payment_method' => 'card', 'payment_method_id' => $pmId,
        ])));
    }

    private function driveToCompletion(Trip $trip, int $minutes = 15): Trip
    {
        TripOffer::query()->create(['trip_id' => $trip->id, 'driver_id' => $this->driver->id, 'offered_at' => now(), 'expires_at' => now()->addSeconds(20)]);
        $d = $this->actingAs($this->driver);
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk();
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/start", ['pin' => $trip->fresh()->pin])->assertOk();
        $this->travel($minutes)->minutes();
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/complete", ['lat' => self::OISTINS[0], 'lng' => self::OISTINS[1]])->assertOk();

        return $trip->fresh();
    }

    // ---------------------------------------------------------------- saved cards

    public function test_add_card_via_hosted_page_becomes_default(): void
    {
        $id = $this->addCard();
        $this->actingAs($this->rider)->getJson('/api/v1/me/payment-methods')->assertOk()
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.last4', '4242')->assertJsonPath('0.is_default', true);
    }

    public function test_card_ride_without_card_is_rejected(): void
    {
        $this->requestCardRide()->assertStatus(422)->assertJsonValidationErrors('payment_method_id');
    }

    // ---------------------------------------------------------------- holds

    public function test_hold_placed_at_request_then_dispatch_starts(): void
    {
        $this->addCard();
        $res = $this->requestCardRide()->assertCreated()->assertJsonPath('status', 'requested');
        $trip = Trip::query()->where('public_id', $res->json('id'))->first();

        $hold = $trip->hold();
        $this->assertSame('authorized', $hold->status);
        $this->assertEqualsWithDelta($trip->estimated_fare * 1.2, $hold->authorized_amount, 0.5, 'estimate + 20%, rounded up');
        $this->assertGreaterThanOrEqual($trip->estimated_fare * 1.2, $hold->authorized_amount);
        Queue::assertPushed(DispatchTripJob::class);
    }

    public function test_3ds_card_waits_for_rider_then_dispatches(): void
    {
        $this->addCard('4000000000003220');
        $res = $this->requestCardRide()->assertStatus(202)->assertJsonPath('status', 'pending_payment');
        Queue::assertNotPushed(DispatchTripJob::class);

        $page = $this->get($res->json('payment_action.url'))->assertOk();
        preg_match('/action="([^"]+approve[^"]*)"/', $page->getContent(), $m);
        $this->post(html_entity_decode($m[1]))->assertRedirect();

        $trip = Trip::query()->where('public_id', $res->json('id'))->first();
        $this->assertSame('requested', $trip->status->value);
        $this->assertSame('authorized', $trip->hold()->status);
        Queue::assertPushed(DispatchTripJob::class);
    }

    public function test_declined_hold_cancels_request(): void
    {
        $this->addCard('4000000000000002');
        $res = $this->requestCardRide()->assertStatus(402)->assertJsonPath('trip.status', 'cancelled');
        $this->assertSame('failed', Trip::query()->where('public_id', $res->json('trip.id'))->first()->payment_status->value);
        Queue::assertNotPushed(DispatchTripJob::class);
        // Not owed anything — can request again with cash.
        $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS))->assertCreated();
    }

    public function test_final_fare_captured_from_hold_and_rest_released(): void
    {
        $this->addCard();
        $trip = Trip::query()->where('public_id', $this->requestCardRide()->json('id'))->first();
        $trip = $this->driveToCompletion($trip, 10);

        $hold = $trip->hold();
        $this->assertSame('captured', $hold->status);
        $this->assertEqualsWithDelta($trip->final_fare, $hold->captured_amount, 0.001);
        $this->assertSame('paid', $trip->payment_status->value);
        $this->assertSame(0.0, $trip->amount_due);
    }

    public function test_overrun_beyond_hold_is_topped_up_from_saved_card(): void
    {
        $this->addCard();
        $trip = Trip::query()->where('public_id', $this->requestCardRide()->json('id'))->first();
        $trip->hold()->update(['authorized_amount' => 5.00]); // pretend the hold was tiny
        $trip = $this->driveToCompletion($trip, 25);

        $this->assertSame('paid', $trip->payment_status->value);
        $topUp = Payment::query()->where('trip_id', $trip->id)->where('purpose', 'top_up')->first();
        $this->assertNotNull($topUp);
        $this->assertEqualsWithDelta($trip->final_fare - 5.00, $topUp->amount, 0.001);
    }

    public function test_cancel_before_accept_voids_hold(): void
    {
        $this->addCard();
        $trip = Trip::query()->where('public_id', $this->requestCardRide()->json('id'))->first();
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/cancel")->assertOk()->assertJsonPath('cancellation_fee', 0.0);
        $this->assertSame('voided', $trip->hold()->status);
    }

    public function test_cancel_after_accept_takes_fee_from_hold(): void
    {
        $this->addCard();
        $trip = Trip::query()->where('public_id', $this->requestCardRide()->json('id'))->first();
        TripOffer::query()->create(['trip_id' => $trip->id, 'driver_id' => $this->driver->id, 'offered_at' => now(), 'expires_at' => now()->addSeconds(20)]);
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();

        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/cancel")
            ->assertOk()->assertJsonPath('payment_status', 'paid')->assertJsonPath('amount_due', 0.0);
        $this->assertSame('captured', $trip->hold()->status);
        $this->assertEqualsWithDelta(5.88, $trip->hold()->captured_amount, 0.001);
    }

    public function test_no_drivers_timeout_releases_hold(): void
    {
        $this->addCard();
        $trip = Trip::query()->where('public_id', $this->requestCardRide()->json('id'))->first();
        $this->travel(11)->minutes();
        $this->artisan('trips:expire-stale')->assertSuccessful();
        $this->assertSame('no_drivers', $trip->fresh()->status->value);
        $this->assertSame('voided', $trip->hold()->status);
    }

    public function test_card_in_use_cannot_be_removed(): void
    {
        $id = $this->addCard();
        $this->requestCardRide()->assertCreated();
        $this->actingAs($this->rider)->deleteJson("/api/v1/me/payment-methods/{$id}")->assertStatus(409);
    }

    // ---------------------------------------------------------------- amounts owed

    private function owedTrip(): Trip
    {
        return Trip::query()->create([
            'rider_id' => $this->rider->id, 'service_area_id' => \App\Models\ServiceArea::query()->where('code', 'BB')->value('id'),
            'status' => 'completed', 'payment_method' => 'cash',
            'pickup_lat' => self::BRIDGETOWN[0], 'pickup_lng' => self::BRIDGETOWN[1], 'dropoff_lat' => self::OISTINS[0], 'dropoff_lng' => self::OISTINS[1],
            'currency' => 'BBD', 'final_fare' => 32.50, 'amount_due' => 32.50, 'payment_status' => 'pending', 'completed_at' => now(),
        ]);
    }

    public function test_outstanding_balance_blocks_new_requests(): void
    {
        $this->owedTrip();
        $this->actingAs($this->rider)->getJson('/api/v1/me/outstanding')->assertOk()->assertJsonCount(1);
        $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS))
            ->assertStatus(422)->assertJsonValidationErrors('payment');
    }

    public function test_pay_owed_amount_on_hosted_page(): void
    {
        $trip = $this->owedTrip();
        $res = $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/pay")->assertOk();
        $this->assertSame($res->json('payment.id'), $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/pay")->json('payment.id'), 'resumes');

        $page = $this->get($res->json('redirect_url'))->assertOk()->assertSee('TEST GATEWAY');
        preg_match('/action="([^"]+approve[^"]*)"/', $page->getContent(), $m);
        $this->post(html_entity_decode($m[1]))->assertRedirect();

        $this->assertSame('paid', $trip->fresh()->payment_status->value);
        $this->get(route('payments.return', ['payment' => $res->json('payment.id')]))->assertOk()->assertSee('Payment complete');
    }

    public function test_pay_owed_amount_with_saved_card(): void
    {
        $id = $this->addCard();
        $trip = $this->owedTrip();
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/pay", ['payment_method_id' => $id])
            ->assertOk()->assertJsonPath('payment.status', 'captured');
        $this->assertSame(0.0, $trip->fresh()->amount_due);
    }

    public function test_unsigned_test_pages_are_rejected(): void
    {
        $trip = $this->owedTrip();
        $pid = $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/pay")->json('payment.id');
        $this->get("/api/v1/payments/test/{$pid}")->assertForbidden();
    }

    public function test_card_unavailable_without_gateway(): void
    {
        Gateway::query()->where('type', 'payment')->update(['is_active' => false]);
        $this->actingAs($this->rider)->postJson('/api/v1/me/payment-methods', ['service_area_id' => \App\Models\ServiceArea::query()->value('id')])
            ->assertStatus(422)->assertJsonValidationErrors('card');
    }

    public function test_admin_can_waive(): void
    {
        $trip = $this->owedTrip();
        $this->actingAs($this->makeUser('admin'))->postJson("/api/v1/admin/trips/{$trip->public_id}/waive", ['reason' => 'Goodwill'])
            ->assertOk()->assertJsonPath('payment_status', 'waived');
        $this->actingAs($this->rider)->getJson('/api/v1/me/outstanding')->assertJsonCount(0);
    }
}
