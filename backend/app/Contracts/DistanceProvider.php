<?php

namespace App\Contracts;

use App\Support\RouteEstimate;

interface DistanceProvider
{
    /** Estimate road distance and duration between two points. */
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): RouteEstimate;
}
