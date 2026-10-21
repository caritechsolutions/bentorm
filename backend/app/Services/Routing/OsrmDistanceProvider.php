<?php

namespace App\Services\Routing;

use App\Contracts\DistanceProvider;
use App\Support\RouteEstimate;
use Illuminate\Support\Facades\Http;

/** Uses an OSRM server (self-hosted or public) for real road routing; falls back to haversine on error. */
class OsrmDistanceProvider implements DistanceProvider
{
    public function __construct(private readonly HaversineDistanceProvider $fallback) {}

    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): RouteEstimate
    {
        $base = rtrim(config('pricing.osrm_url', 'https://router.project-osrm.org'), '/');
        $url = "{$base}/route/v1/driving/{$fromLng},{$fromLat};{$toLng},{$toLat}";

        try {
            $res = Http::timeout(5)->get($url, ['overview' => 'simplified', 'geometries' => 'geojson']);
            $route = $res->json('routes.0');
            if ($res->successful() && $route) {
                return new RouteEstimate(
                    (int) round($route['distance']),
                    (int) round($route['duration']),
                    $route['geometry']['coordinates'] ?? null,
                );
            }
        } catch (\Throwable) {
            // fall through
        }

        return $this->fallback->estimate($fromLat, $fromLng, $toLat, $toLng);
    }
}
