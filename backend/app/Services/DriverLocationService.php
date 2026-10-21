<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Redis;

/**
 * Live driver presence and position, kept in Redis (phpredis client).
 *
 *  drivers:geo        GEO set, member = driver user id
 *  driver:{id}        HASH  lat, lng, heading, speed, vehicle_type, state (idle|busy), updated_at
 *  driver:{id}:hb     heartbeat key with TTL — missing means the driver dropped off
 */
class DriverLocationService
{
    private const GEO_KEY = 'drivers:geo';

    public function update(User $driver, float $lat, float $lng, ?float $heading = null, ?float $speed = null, ?string $vehicleType = null): void
    {
        $id = (string) $driver->id;
        $hash = [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading ?? '',
            'speed' => $speed ?? '',
            'updated_at' => now()->timestamp,
        ];
        if ($vehicleType) {
            $hash['vehicle_type'] = $vehicleType;
        }

        Redis::pipeline(function ($pipe) use ($id, $lat, $lng, $hash) {
            $pipe->geoadd(self::GEO_KEY, $lng, $lat, $id);
            $pipe->hmset("driver:{$id}", $hash);
            $pipe->setex("driver:{$id}:hb", $this->heartbeatTtl(), '1');
        });
    }

    public function setState(User $driver, string $state): void
    {
        Redis::hset("driver:{$driver->id}", 'state', $state);
    }

    public function goOnline(User $driver, string $vehicleType): void
    {
        Redis::hmset("driver:{$driver->id}", ['state' => 'idle', 'vehicle_type' => $vehicleType, 'updated_at' => now()->timestamp]);
        Redis::setex("driver:{$driver->id}:hb", $this->heartbeatTtl(), '1');
    }

    public function goOffline(User $driver): void
    {
        $id = (string) $driver->id;
        Redis::pipeline(function ($pipe) use ($id) {
            $pipe->zrem(self::GEO_KEY, $id);
            $pipe->del("driver:{$id}", "driver:{$id}:hb");
        });
    }

    public function isOnline(User|int $driver): bool
    {
        $id = $driver instanceof User ? $driver->id : $driver;

        return (bool) Redis::exists("driver:{$id}:hb");
    }

    /** @return array{lat: float, lng: float, heading: ?float, speed: ?float, updated_at: int}|null */
    public function position(User|int $driver): ?array
    {
        $id = $driver instanceof User ? $driver->id : $driver;
        $h = Redis::hgetall("driver:{$id}");
        if (! $h || ! isset($h['lat'])) {
            return null;
        }

        return [
            'lat' => (float) $h['lat'],
            'lng' => (float) $h['lng'],
            'heading' => $h['heading'] !== '' ? (float) $h['heading'] : null,
            'speed' => $h['speed'] !== '' ? (float) $h['speed'] : null,
            'state' => $h['state'] ?? 'idle',
            'vehicle_type' => $h['vehicle_type'] ?? null,
            'updated_at' => (int) $h['updated_at'],
        ];
    }

    /**
     * Nearest idle, heartbeat-alive drivers of a given vehicle type.
     *
     * @return array<int, array{driver_id: int, distance_m: int}>
     */
    public function nearbyIdle(float $lat, float $lng, int $radiusM, string $vehicleType, int $limit = 10, array $excludeIds = []): array
    {
        // Pull a larger candidate set and filter by state/heartbeat/type.
        $raw = Redis::command('georadius', [self::GEO_KEY, $lng, $lat, $radiusM, 'm', ['WITHDIST', 'ASC', 'COUNT' => max($limit * 5, 25)]]);

        $results = [];
        foreach ($raw ?: [] as $entry) {
            [$member, $dist] = is_array($entry) ? $entry : [$entry, 0];
            $id = (int) $member;
            if (in_array($id, $excludeIds, true)) {
                continue;
            }
            if (! $this->isOnline($id)) {
                // Stale entry — clean it up.
                $this->goOfflineById($id);
                continue;
            }
            $h = Redis::hmget("driver:{$id}", ['state', 'vehicle_type']);
            $state = $h[0] ?? ($h['state'] ?? 'idle');
            $type = $h[1] ?? ($h['vehicle_type'] ?? null);
            if ($state !== 'idle' || $type !== $vehicleType) {
                continue;
            }
            $results[] = ['driver_id' => $id, 'distance_m' => (int) round((float) $dist)];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /** All live drivers with positions — for the admin map. */
    public function allLive(): array
    {
        $ids = Redis::zrange(self::GEO_KEY, 0, -1) ?: [];
        $out = [];
        foreach ($ids as $id) {
            if (! $this->isOnline((int) $id)) {
                continue;
            }
            if ($pos = $this->position((int) $id)) {
                $out[] = ['driver_id' => (int) $id] + $pos;
            }
        }

        return $out;
    }

    private function goOfflineById(int $id): void
    {
        Redis::zrem(self::GEO_KEY, (string) $id);
        Redis::del("driver:{$id}");
    }

    private function heartbeatTtl(): int
    {
        return (int) config('dispatch.heartbeat_ttl_seconds', 90);
    }
}
