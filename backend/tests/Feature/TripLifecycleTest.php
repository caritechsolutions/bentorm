<?php

namespace Tests\Feature;

use App\Enums\DriverStatus;
use App\Enums\OfferResponse;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Jobs\DispatchTripJob;
use App\Models\Trip;
use App\Models\TripOffer;
use App\Models\User;
use App\Services\DriverLocationService;
use Database\Seeders\FareConfigSeeder;
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
        $this->seed(FareConfigSeeder::class);
        Queue::fake();

        // Redis is not available in tests — stub the location service.
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });

        $this->rider = User::query()->create(['name' => 'Rider', 'phone' => '+12465550001', 'role' => 'rider']);
        $this->driver = User::query()->create(['name' => 'Driver', 'phone' => '+12465550002', 'role' => 'driver']);
        $vehicle = $this->driver->vehicles()->create(['make' => 'Toyota', 'model' => 'Corolla', 'plate' => 'ZR1', 'vehicle_type' => 'standard', 'status' => VehicleStatus::Approved]);
        $this->driver->driverProfile()->create(['status' => DriverStatus::Approved, 'is_online' => true, 'current_vehicle_id' => $vehicle->id, 'approved_at' => now()]);
    }

    private function requestTrip(): Trip
    {
        $res = $this->actingAs($this->rider)->postJson('/api/v1/trips', [
            'pickup_lat' => 13.0969, 'pickup_lng' => -59.6145,
            'dropoff_lat' => 13.0667, 'dropoff_lng' => -59.5333,
            'vehicle_type' => 'standard',
        ]);
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

    public function test_full_happy_path(): void
    {
        $trip = $this->requestTrip();
        $this->offerTo($trip, $this->driver);

        $d = $this->actingAs($this->driver);
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk()->assertJsonPath('status', 'accepted');
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk()->assertJsonPath('status', 'arrived');
        $d->postJson("/api/v1/driver/trips/{$trip->public_id}/start")->assertOk()->assertJsonPath('status', 'in_progress');

        $this->travel(15)->minutes();
        $res = $d->postJson("/api/v1/driver/trips/{$trip->public_id}/complete", ['lat' => 13.0667, 'lng' => -59.5333]);
        $res->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('payment_status', 'paid');
        $this->assertGreaterThan(0, $res->json('actual.fare'));

        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/rate", ['score' => 5])->assertOk();
        $this->assertSame(1, $this->driver->driverProfile->fresh()->rating_count);
        $this->assertSame(1, $this->driver->driverProfile->fresh()->completed_trips);
    }

    public function test_second_driver_cannot_accept_taken_trip(): void
    {
        $trip = $this->requestTrip();
        $other = User::query()->create(['name' => 'Other', 'phone' => '+12465550003', 'role' => 'driver']);
        $other->driverProfile()->create(['status' => DriverStatus::Approved]);
        $this->offerTo($trip, $this->driver);
        $this->offerTo($trip, $other);

        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
        $this->actingAs($other)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertStatus(409);

        $this->assertSame(OfferResponse::Withdrawn, TripOffer::query()->where('driver_id', $other->id)->first()->response);
    }

    public function test_rider_cancel_after_accept_incurs_fee(): void
    {
        $trip = $this->requestTrip();
        $this->offerTo($trip, $this->driver);
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();

        $res = $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/cancel", ['reason' => 'Changed plans']);
        $res->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelled_by', 'rider')->assertJsonPath('cancellation_fee', 5);
    }

    public function test_rider_cancel_before_accept_is_free(): void
    {
        $trip = $this->requestTrip();
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/cancel")
            ->assertOk()->assertJsonPath('cancellation_fee', 0)->assertJsonPath('payment_status', 'waived');
    }

    public function test_rider_cannot_have_two_active_trips(): void
    {
        $this->requestTrip();
        $this->actingAs($this->rider)->postJson('/api/v1/trips', [
            'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'dropoff_lat' => 13.0, 'dropoff_lng' => -59.5, 'vehicle_type' => 'standard',
        ])->assertStatus(422);
    }

    public function test_state_machine_rejects_skipping_steps(): void
    {
        $this->assertFalse(TripStatus::Requested->canTransitionTo(TripStatus::InProgress));
        $this->assertFalse(TripStatus::Completed->canTransitionTo(TripStatus::Cancelled));
        $this->assertTrue(TripStatus::Arrived->canTransitionTo(TripStatus::InProgress));
    }
}
