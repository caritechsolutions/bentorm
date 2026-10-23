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
}
