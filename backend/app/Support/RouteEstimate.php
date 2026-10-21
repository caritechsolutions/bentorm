<?php

namespace App\Support;

final class RouteEstimate
{
    public function __construct(
        public readonly int $distanceM,
        public readonly int $durationS,
        public readonly ?array $polyline = null,
    ) {}
}
