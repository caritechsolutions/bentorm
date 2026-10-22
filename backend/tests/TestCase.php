<?php

namespace Tests;

use App\Models\DriverProfile;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Known points in Barbados.
    protected const BRIDGETOWN = [13.0969, -59.6145];
    protected const OISTINS = [13.0667, -59.5333];
    protected const AIRPORT = [13.0746, -59.4925];
    protected const HOLETOWN = [13.1865, -59.6380];
    protected const ST_LUCIA = [13.9094, -60.9789]; // outside BB

    protected function seedReference(): ServiceArea
    {
        $this->seed(ReferenceDataSeeder::class);

        return ServiceArea::query()->where('code', 'BB')->firstOrFail();
    }

    protected function makeUser(string $role = 'rider', array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::query()->create($attrs + ['name' => ucfirst($role)." {$n}", 'phone' => '+1246555'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'role' => $role]);
    }

    /** An approved driver with an approved standard vehicle (documents bypassed). */
    protected function makeApprovedDriver(?ServiceArea $area = null): User
    {
        $driver = $this->makeUser('driver');
        $vehicle = Vehicle::query()->create(['driver_id' => $driver->id, 'make' => 'Toyota', 'model' => 'Corolla', 'plate' => 'Z'.$driver->id, 'vehicle_type' => 'standard', 'status' => 'approved']);
        DriverProfile::query()->create(['user_id' => $driver->id, 'service_area_id' => $area?->id, 'status' => 'approved', 'is_online' => true, 'current_vehicle_id' => $vehicle->id, 'approved_at' => now()]);

        return $driver->fresh();
    }

    protected function tripPayload(array $from, array $to, array $extra = []): array
    {
        return $extra + ['pickup_lat' => $from[0], 'pickup_lng' => $from[1], 'dropoff_lat' => $to[0], 'dropoff_lng' => $to[1], 'vehicle_type' => 'standard'];
    }
}
