<?php

namespace App\Contracts;

use App\Support\RouteEstimate;

interface DistanceProvider
{
    /** Estimate road distance and duration between two points. */
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): RouteEstimate;

    /**
     * Road distance and duration through points in order (pickup, stops…, drop-off).
     *
     * @param  array<int, array{0: float, 1: float}>  $points  [[lat, lng], …], at least two
     */
    public function route(array $points): RouteEstimate;

    /**
     * Driving time from each origin to one destination (used to rank drivers by arrival time).
     *
     * @param  array<int, array{0: float, 1: float}>  $origins  [[lat, lng], …]
     * @return array<int, int|null> seconds, same keys as $origins; null when no route was found
     */
    public function etaMany(array $origins, float $toLat, float $toLng): array;
}
