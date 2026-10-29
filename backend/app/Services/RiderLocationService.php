<?php

namespace App\Services;

use App\Models\Trip;
use Illuminate\Support\Facades\Cache;

/**
 * Where the rider's phone is while their driver comes (they may have walked from the pickup pin). Kept two
 * minutes per trip; only that trip's driver sees it.
 */
class RiderLocationService
{
    public const FRESH_SECONDS = 120;

    public function put(Trip $trip, float $lat, float $lng, ?float $accuracy = null): void
    {
        Cache::put($this->key($trip), ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'accuracy' => $accuracy, 'at' => now()->timestamp], self::FRESH_SECONDS);
    }

    /** @return array{lat: float, lng: float, accuracy: ?float, at: int}|null */
    public function get(Trip $trip): ?array
    {
        $p = Cache::get($this->key($trip));

        return is_array($p) && $p['at'] >= now()->timestamp - self::FRESH_SECONDS ? $p : null;
    }

    private function key(Trip $trip): string
    {
        return "trip:{$trip->id}:rider_pos";
    }
}
