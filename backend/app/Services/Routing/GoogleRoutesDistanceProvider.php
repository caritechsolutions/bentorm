<?php

namespace App\Services\Routing;

use App\Contracts\DistanceProvider;
use App\Support\Polyline;
use App\Support\RouteEstimate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Routes API (computeRoutes, DRIVE, traffic-unaware = Essentials tier).
 * Results are cached for 10 minutes per ~10 m grid so estimate → request reuses one lookup.
 * Falls back to the haversine estimate if Google is unreachable or returns no route.
 */
class GoogleRoutesDistanceProvider implements DistanceProvider
{
    public const URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public function __construct(private readonly HaversineDistanceProvider $fallback) {}

    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): RouteEstimate
    {
        return $this->route([[$fromLat, $fromLng], [$toLat, $toLng]]);
    }

    public function route(array $points): RouteEstimate
    {
        $key = config('services.google_maps.server_key');
        if (! $key || count($points) < 2) {
            return $this->fallback->route($points);
        }

        $cacheKey = 'route:g:'.implode(':', array_map(fn ($p) => sprintf('%.4f,%.4f', $p[0], $p[1]), $points));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return new RouteEstimate($cached['d'], $cached['t'], $cached['p']);
        }

        $loc = fn (array $p) => ['location' => ['latLng' => ['latitude' => (float) $p[0], 'longitude' => (float) $p[1]]]];
        $body = [
            'origin' => $loc($points[0]),
            'destination' => $loc($points[count($points) - 1]),
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
        ];
        if (count($points) > 2) {
            $body['intermediates'] = array_map($loc, array_slice($points, 1, -1));
        }

        try {
            $res = Http::timeout(6)
                ->withHeaders([
                    'X-Goog-Api-Key' => $key,
                    'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline',
                ])
                ->post(self::URL, $body);
            $route = $res->json('routes.0');
            if ($res->successful() && isset($route['distanceMeters'])) {
                $estimate = new RouteEstimate(
                    (int) $route['distanceMeters'],
                    (int) rtrim((string) ($route['duration'] ?? '0s'), 's'),
                    isset($route['polyline']['encodedPolyline']) ? Polyline::decode($route['polyline']['encodedPolyline']) : null,
                );
                Cache::put($cacheKey, ['d' => $estimate->distanceM, 't' => $estimate->durationS, 'p' => $estimate->polyline], now()->addMinutes(10));

                return $estimate;
            }
            Log::warning('Google Routes returned no route', ['status' => $res->status(), 'body' => mb_substr(preg_replace('/\s+/', ' ', $res->body()), 0, 400)]);
        } catch (\Throwable $e) {
            Log::warning('Google Routes failed: '.$e->getMessage());
        }

        return $this->fallback->route($points);
    }
}
