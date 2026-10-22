<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripOffer;
use App\Services\DispatchService;
use App\Services\DriverLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class CapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotes_only_include_types_that_fit(): void
    {
        $this->seedReference();
        $types = fn (array $extra) => collect($this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, $extra + ['vehicle_type' => null]))
            ->assertOk()->json('quotes'))->pluck('vehicle_type')->all();

        $this->assertSame(['standard', 'xl', 'premium'], $types([]));
        $this->assertSame(['xl'], $types(['passengers' => 5]));
        $this->assertSame(['xl', 'premium'], $types(['luggage' => 3]));
        $this->assertSame([], $types(['passengers' => 7]));
    }

    public function test_request_rejects_party_too_big_for_type(): void
    {
        $this->seedReference();
        Queue::fake();
        $this->actingAs($this->makeUser())->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['passengers' => 5]))
            ->assertStatus(422)->assertJsonValidationErrors('passengers');
        $this->actingAs($this->makeUser())->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['features' => ['jetpack']]))
            ->assertStatus(422)->assertJsonValidationErrors('features.0');
    }

    public function test_dispatch_skips_vehicles_without_requested_features_or_space(): void
    {
        $bb = $this->seedReference();
        Queue::fake();

        $noSeat = $this->makeApprovedDriver($bb);       // nearest, but no child seat
        $small = $this->makeApprovedDriver($bb);        // has child seat, only 1 bag
        $good = $this->makeApprovedDriver($bb);         // has child seat and room
        $noSeat->driverProfile->currentVehicle->update(['features' => [], 'luggage_capacity' => 3]);
        $small->driverProfile->currentVehicle->update(['features' => ['child_seat'], 'luggage_capacity' => 1]);
        $good->driverProfile->currentVehicle->update(['features' => ['child_seat', 'pet_friendly'], 'luggage_capacity' => 2]);

        $this->mock(DriverLocationService::class, function (MockInterface $m) use ($noSeat, $small, $good) {
            $m->shouldReceive('nearbyIdle')->andReturn([
                ['driver_id' => $noSeat->id, 'distance_m' => 200],
                ['driver_id' => $small->id, 'distance_m' => 400],
                ['driver_id' => $good->id, 'distance_m' => 900],
            ]);
        });

        $trip = Trip::query()->create([
            'rider_id' => $this->makeUser()->id, 'service_area_id' => $bb->id, 'status' => 'requested', 'vehicle_type' => 'standard',
            'pickup_lat' => self::BRIDGETOWN[0], 'pickup_lng' => self::BRIDGETOWN[1], 'dropoff_lat' => self::OISTINS[0], 'dropoff_lng' => self::OISTINS[1],
            'passengers' => 2, 'luggage' => 2, 'required_features' => ['child_seat'], 'requested_at' => now(),
        ]);

        app(DispatchService::class)->runWave($trip);

        $this->assertSame([$good->id], TripOffer::query()->where('trip_id', $trip->id)->pluck('driver_id')->all());
    }

    public function test_driver_declares_capacity_and_features(): void
    {
        $this->seedReference();
        $driver = $this->makeUser('driver');
        $this->actingAs($driver)->postJson('/api/v1/driver/vehicles', [
            'make' => 'Toyota', 'model' => 'Noah', 'plate' => 'zm 123', 'vehicle_type' => 'xl', 'seats' => 7, 'luggage_capacity' => 4,
            'features' => ['wheelchair_accessible'],
        ])->assertCreated()->assertJsonPath('plate', 'ZM123')->assertJsonPath('features.0', 'wheelchair_accessible')->assertJsonPath('luggage_capacity', 4);

        $this->getJson('/api/v1/vehicle-features')->assertOk()->assertJsonCount(4);
    }
}
