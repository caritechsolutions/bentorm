<?php

namespace App\Services\Routing;

use App\Contracts\DistanceProvider;
use App\Support\Geo;
use App\Support\RouteEstimate;

/**
 * Straight-line estimate scaled by a road factor. Good enough for Barbados-scale trips
 * until a routing engine (OSRM / Google / Mapbox) is wired in.
 */
class HaversineDistanceProvider implements DistanceProvider
{
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): RouteEstimate
    {
        $factor = (float) config('pricing.road_factor', 1.3);
        $speedKmh = (float) config('pricing.assumed_speed_kmh', 30);

        $distanceM = (int) round(Geo::haversine($fromLat, $fromLng, $toLat, $toLng) * $factor);
        $durationS = (int) round($distanceM / 1000 / $speedKmh * 3600);

        return new RouteEstimate($distanceM, $durationS);
    }

    public function etaMany(array $origins, float $toLat, float $toLng): array
    {
        return array_map(fn (array $o) => $this->estimate($o[0], $o[1], $toLat, $toLng)->durationS, $origins);
    }

    public function route(array $points): RouteEstimate
    {
        $d = 0;
        $t = 0;
        for ($i = 1; $i < count($points); $i++) {
            $leg = $this->estimate($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
            $d += $leg->distanceM;
            $t += $leg->durationS;
        }

        return new RouteEstimate($d, $t);
    }
}
