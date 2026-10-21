<?php

namespace App\Services;

use App\Contracts\DistanceProvider;
use App\Models\FareConfig;
use App\Support\RouteEstimate;
use Illuminate\Validation\ValidationException;

class PricingService
{
    public function __construct(private readonly DistanceProvider $distance) {}

    /**
     * Estimate for a single vehicle type.
     *
     * @return array{vehicle_type:string, currency:string, distance_m:int, duration_s:int, fare:float, breakdown:array, surge_multiplier:float}
     */
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng, string $vehicleType): array
    {
        $config = FareConfig::forType($vehicleType);
        if (! $config) {
            throw ValidationException::withMessages(['vehicle_type' => ['Unknown vehicle type.']]);
        }

        $route = $this->distance->estimate($fromLat, $fromLng, $toLat, $toLng);

        return $this->quote($config, $route);
    }

    /** Estimates for every active vehicle type, cheapest first by sort_order. */
    public function estimateAll(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $route = $this->distance->estimate($fromLat, $fromLng, $toLat, $toLng);

        return FareConfig::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (FareConfig $c) => $this->quote($c, $route) + [
                'display_name' => $c->display_name,
                'description' => $c->description,
                'max_passengers' => $c->max_passengers,
            ])
            ->values()
            ->all();
    }

    /** Final fare from actual trip metrics. */
    public function finalFare(string $vehicleType, int $distanceM, int $durationS, float $surge = 1.0): array
    {
        $config = FareConfig::forType($vehicleType) ?? FareConfig::query()->where('vehicle_type', $vehicleType)->firstOrFail();

        return $this->quote($config, new RouteEstimate($distanceM, $durationS), $surge);
    }

    private function quote(FareConfig $c, RouteEstimate $route, ?float $surge = null): array
    {
        $surge = $surge ?? (float) $c->surge_multiplier;
        $km = $route->distanceM / 1000;
        $min = $route->durationS / 60;

        $distanceCost = round($km * (float) $c->per_km, 2);
        $timeCost = round($min * (float) $c->per_minute, 2);
        $subtotal = ((float) $c->base_fare + $distanceCost + $timeCost) * $surge;
        $subtotal = max($subtotal, (float) $c->minimum_fare);
        $total = $this->roundUp($subtotal + (float) $c->booking_fee);

        return [
            'vehicle_type' => $c->vehicle_type,
            'currency' => $c->currency,
            'distance_m' => $route->distanceM,
            'duration_s' => $route->durationS,
            'fare' => $total,
            'surge_multiplier' => $surge,
            'breakdown' => [
                'base_fare' => (float) $c->base_fare,
                'distance_km' => round($km, 2),
                'distance_cost' => $distanceCost,
                'duration_min' => round($min, 1),
                'time_cost' => $timeCost,
                'surge_multiplier' => $surge,
                'minimum_fare' => (float) $c->minimum_fare,
                'booking_fee' => (float) $c->booking_fee,
                'total' => $total,
            ],
        ];
    }

    private function roundUp(float $amount): float
    {
        $step = (float) config('pricing.rounding', 0.5);
        if ($step <= 0) {
            return round($amount, 2);
        }

        return round(ceil($amount / $step) * $step, 2);
    }
}
