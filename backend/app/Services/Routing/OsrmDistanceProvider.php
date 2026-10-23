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
        return $this->route([[$fromLat, $fromLng], [$toLat, $toLng]]);
    }

    /** OSRM table service: all origins → one destination in a single request. */
    public function etaMany(array $origins, float $toLat, float $toLng): array
    {
        if (! $origins) {
            return [];
        }
        $keys = array_keys($origins);
        $base = rtrim(config('pricing.osrm_url', 'https://router.project-osrm.org'), '/');
        $coords = implode(';', array_map(fn ($p) => "{$p[1]},{$p[0]}", array_values($origins))).";{$toLng},{$toLat}";
        $n = count($origins);
        try {
            $res = Http::timeout(5)->get("{$base}/table/v1/driving/{$coords}", [
                'sources' => implode(';', range(0, $n - 1)),
                'destinations' => (string) $n,
                'annotations' => 'duration',
            ]);
            $rows = $res->json('durations');
            if ($res->successful() && is_array($rows) && count($rows) === $n) {
                $out = [];
                foreach ($keys as $i => $k) {
                    $v = $rows[$i][0] ?? null;
                    $out[$k] = $v === null ? null : (int) round($v);
                }

                return $out;
            }
        } catch (\Throwable) {
            // fall through
        }

        return $this->fallback->etaMany($origins, $toLat, $toLng);
    }

    public function route(array $points): RouteEstimate
    {
        $base = rtrim(config('pricing.osrm_url', 'https://router.project-osrm.org'), '/');
        $coords = implode(';', array_map(fn ($p) => "{$p[1]},{$p[0]}", $points));
        $url = "{$base}/route/v1/driving/{$coords}";

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

        return $this->fallback->route($points);
    }
}
