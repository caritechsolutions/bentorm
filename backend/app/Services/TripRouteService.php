<?php

namespace App\Services;

use App\Contracts\DistanceProvider;
use App\Enums\TripStatus;
use App\Models\Trip;
use Illuminate\Support\Facades\Cache;

/**
 * The road line the rider's map draws: the driver's way to the pickup while they're coming, then the trip itself.
 * Cached per trip so the apps' frequent refreshes cost one routing lookup a minute at most (driver approach) or one
 * for the whole ride. Without a road route (no Google key) the line is null and the app draws a straight line.
 */
class TripRouteService
{
    public function __construct(private readonly DistanceProvider $routes) {}

    /** @return array{phase: string, polyline: ?array, distance_m: int, duration_s: int}|null polyline as [[lat, lng], …] */
    public function forTrip(Trip $trip, ?array $driverPos): ?array
    {
        $status = $trip->status;
        if (in_array($status, [TripStatus::Accepted, TripStatus::Arrived], true) && $driverPos) {
            return Cache::remember("trip:{$trip->id}:route:approach", now()->addMinute(), fn () => $this->shape('to_pickup',
                [[(float) $driverPos['lat'], (float) $driverPos['lng']], [(float) $trip->pickup_lat, (float) $trip->pickup_lng]]));
        }
        if ($status === TripStatus::InProgress || in_array($status, [TripStatus::Requested, TripStatus::Accepted, TripStatus::Arrived, TripStatus::Scheduled], true)) {
            $points = [[(float) $trip->pickup_lat, (float) $trip->pickup_lng]];
            foreach ($trip->stops ?? [] as $s) {
                $points[] = [(float) $s['lat'], (float) $s['lng']];
            }
            $points[] = [(float) $trip->dropoff_lat, (float) $trip->dropoff_lng];

            return Cache::remember("trip:{$trip->id}:route:ride:".md5(json_encode($points)), now()->addHours(3), fn () => $this->shape('ride', $points));
        }

        return null;
    }

    private function shape(string $phase, array $points): array
    {
        $r = $this->routes->route($points);

        return [
            'phase' => $phase,
            'polyline' => $r->polyline ? array_map(fn ($p) => [(float) $p[1], (float) $p[0]], $r->polyline) : null,
            'distance_m' => $r->distanceM,
            'duration_s' => $r->durationS,
        ];
    }
}
