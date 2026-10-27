<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Redis;

/**
 * Live driver presence and position, kept in Redis (phpredis client).
 *
 *  drivers:geo        GEO set, member = driver user id
 *  driver:{id}        HASH  lat, lng, heading, speed, vehicle_type, state (idle|busy), idle_since, updated_at
 *  driver:{id}:hb     heartbeat key with TTL — missing means the driver dropped off
 */
class DriverLocationService
{
    private const GEO_KEY = 'drivers:geo';

    /**
     * A position ping. With $state, presence is re-stated in full: vehicle type, idle/busy (keeping the time the
     * driver became idle, for "longest waiting" dispatch) — the ping alone puts a dropped driver back.
     */
    public function update(User $driver, float $lat, float $lng, ?float $heading = null, ?float $speed = null, ?string $vehicleType = null, ?string $state = null): void
    {
        $id = (string) $driver->id;
        if ($state !== null) {
            $now = array_values((array) Redis::hmget("driver:{$id}", ['state', 'idle_since']));
            if (($now[0] ?? null) !== $state) {
                $this->setState($driver, $state);
            } elseif ($state === 'idle' && ! is_numeric($now[1] ?? null)) {
                Redis::hset("driver:{$id}", 'idle_since', now()->timestamp);
            }
        }
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
        $fields = ['state' => $state];
        if ($state === 'idle') {
            $fields['idle_since'] = now()->timestamp; // waiting for a ride from now (fair "longest waiting" dispatch)
        }
        Redis::hmset("driver:{$driver->id}", $fields);
    }

    public function goOnline(User $driver, string $vehicleType): void
    {
        Redis::hmset("driver:{$driver->id}", ['state' => 'idle', 'vehicle_type' => $vehicleType, 'idle_since' => now()->timestamp, 'updated_at' => now()->timestamp]);
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
     * Nearest idle, heartbeat-alive drivers of a given vehicle type (null = any type, e.g. deliveries).
     *
     * @return array<int, array{driver_id: int, distance_m: int, lat: ?float, lng: ?float, idle_since: ?int}>
     */
    public function nearbyIdle(float $lat, float $lng, int $radiusM, ?string $vehicleType, int $limit = 10, array $excludeIds = []): array
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
                // No ping for a while: off the map, but keep the record — the next ping puts them straight back.
                Redis::zrem(self::GEO_KEY, (string) $id);
                continue;
            }
            $h = array_values((array) Redis::hmget("driver:{$id}", ['state', 'vehicle_type', 'lat', 'lng', 'idle_since']));
            $state = $h[0] ?: 'idle';
            $type = $h[1] ?: null;
            if ($state !== 'idle' || ($vehicleType !== null && $type !== $vehicleType)) {
                continue;
            }
            $results[] = [
                'driver_id' => $id,
                'distance_m' => (int) round((float) $dist),
                'lat' => is_numeric($h[2] ?? null) ? (float) $h[2] : null,
                'lng' => is_numeric($h[3] ?? null) ? (float) $h[3] : null,
                'idle_since' => is_numeric($h[4] ?? null) ? (int) $h[4] : null,
            ];
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

    /** Unix time of the driver's last ping, or null if none is known. */
    public function lastSeen(int $id): ?int
    {
        $t = Redis::hget("driver:{$id}", 'updated_at');

        return is_numeric($t) ? (int) $t : null;
    }

    /**
     * Free cars near a point, for the rider's map before booking. Rough positions only (about 100 m), no ids.
     *
     * @return array<int, array{lat: float, lng: float, heading: ?float, vehicle_type: ?string}>
     */
    public function carsNear(float $lat, float $lng, int $radiusM = 4000, int $limit = 15): array
    {
        $raw = Redis::command('georadius', [self::GEO_KEY, $lng, $lat, $radiusM, 'm', ['ASC', 'COUNT' => $limit * 3]]);
        $out = [];
        foreach ($raw ?: [] as $member) {
            $id = (int) (is_array($member) ? $member[0] : $member);
            if (! $this->isOnline($id)) {
                continue;
            }
            $h = array_values((array) Redis::hmget("driver:{$id}", ['state', 'vehicle_type', 'lat', 'lng', 'heading']));
            if (($h[0] ?: 'idle') !== 'idle' || ! is_numeric($h[2] ?? null) || ! is_numeric($h[3] ?? null)) {
                continue;
            }
            $out[] = ['lat' => round((float) $h[2], 3), 'lng' => round((float) $h[3], 3),
                'heading' => is_numeric($h[4] ?? null) ? ((int) round((float) $h[4] / 15) * 15) % 360 : null, 'vehicle_type' => $h[1] ?: null];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function heartbeatTtl(): int
    {
        return (int) config('dispatch.heartbeat_ttl_seconds', 90);
    }
}
