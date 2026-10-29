<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 28 Oct: the pickup pin was wrong (2 km off) and the driver started the ride where the rider really was. A driver
 * may now tap Arrived / Start only near the rider's phone or the pickup pin (admin setting, default 150 m).
 */
class NearRiderTest extends TestCase
{
    use RefreshDatabase;

    private array $driverAt;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->driverAt = ['lat' => self::OISTINS[0], 'lng' => self::OISTINS[1]];
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturnUsing(fn () => $this->driverAt + ['heading' => null, 'speed' => null, 'state' => 'busy', 'vehicle_type' => 'standard', 'updated_at' => now()->timestamp]);
        });
        $this->seedReference();
    }

    /** @return array{0: Trip, 1: User, 2: User} */
    private function acceptedRide(): array
    {
        $rider = $this->makeUser();
        $driver = $this->makeApprovedDriver();
        $id = $this->actingAs($rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::AIRPORT, ['payment_method' => 'cash']))->assertSuccessful()->json('id');
        $trip = Trip::query()->where('public_id', $id)->first();
        $trip->forceFill(['driver_id' => $driver->id, 'status' => TripStatus::Accepted, 'accepted_at' => now()])->save();

        return [$trip->fresh(), $rider, $driver];
    }

    public function test_the_driver_must_be_near_the_rider_or_the_pickup_to_arrive_and_start(): void
    {
        [$trip, $rider, $driver] = $this->acceptedRide();

        // Driver 9 km away at Oistins; the rider's phone hasn't reported: refused, with the distance.
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")
            ->assertStatus(409)->assertJsonPath('max_distance_m', 150)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'km from') && str_contains($m, 'within 150 m of the pickup'));

        // The rider walked to where the driver is (the pin was wrong): their phone says so → allowed.
        $this->actingAs($rider, 'sanctum')->postJson("/api/v1/trips/{$trip->public_id}/rider-location", ['lat' => self::OISTINS[0] + 0.0005, 'lng' => self::OISTINS[1], 'accuracy' => 12])
            ->assertOk()->assertJsonPath('shared', true);
        $this->actingAs($driver, 'sanctum')->getJson("/api/v1/driver/trips/{$trip->public_id}")->assertJsonPath('rider_position.lat', round(self::OISTINS[0] + 0.0005, 6));
        $this->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk();
        $this->postJson("/api/v1/driver/trips/{$trip->public_id}/start", ['pin' => $trip->fresh()->pin])->assertOk()->assertJsonPath('status', 'in_progress');

        // During the ride the rider's position isn't stored or shown any more.
        $this->actingAs($rider, 'sanctum')->postJson("/api/v1/trips/{$trip->public_id}/rider-location", ['lat' => 13.1, 'lng' => -59.6])->assertJsonPath('shared', false);
        $this->actingAs($this->makeUser(), 'sanctum')->postJson("/api/v1/trips/{$trip->public_id}/rider-location", ['lat' => 13.1, 'lng' => -59.6])->assertNotFound();
    }

    public function test_at_the_pickup_pin_is_fine_and_the_check_can_be_switched_off(): void
    {
        [$trip, , $driver] = $this->acceptedRide();
        $this->driverAt = ['lat' => self::BRIDGETOWN[0] + 0.0008, 'lng' => self::BRIDGETOWN[1]]; // ~90 m from the pin
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk();

        [$trip2, , $driver2] = $this->acceptedRide();
        $this->driverAt = ['lat' => self::OISTINS[0], 'lng' => self::OISTINS[1]];
        Features::put('pickup.max_distance_m', 0);
        $this->actingAs($driver2, 'sanctum')->postJson("/api/v1/driver/trips/{$trip2->public_id}/arrive")->assertOk();
    }
}
