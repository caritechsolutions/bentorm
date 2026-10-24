<?php

namespace App\Services;

use App\Contracts\DistanceProvider;
use App\Enums\FareRuleKind;
use App\Models\FareConfig;
use App\Models\FareRule;
use App\Models\ServiceArea;
use App\Models\Trip;
use App\Models\VehicleType;
use App\Support\GeoContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Fare engine.
 *
 *   metered  = (base + km × per_km + min × per_minute) × time_multiplier × surge, floored at minimum_fare
 *   or fixed = a fixed_route rule's amount when pickup/drop-off zones match (replaces metered; no multipliers or zone fees)
 *   + waiting  = minutes beyond free_wait_minutes × wait_per_minute (final fare only)
 *   + zone fees (airport etc.)
 *   + booking fee
 *   Rate-card amounts are ex-tax. Tax is added on top, then the total is rounded UP to the area's increment and
 *   the rounding is shown as its own line, so: sum(lines excluding tax) = subtotal; subtotal + tax = total.
 *   platform_fee = subtotal (ex tax) × commission_rate; driver_earnings = subtotal − platform_fee.
 *
 * The multiplier and any fixed route are locked when the trip is requested and reused for the final fare.
 */
class PricingService
{
    public function __construct(
        private readonly DistanceProvider $distance,
        private readonly GeoService $geo,
    ) {}

    /** Quotes for every vehicle type offered in the pickup's service area that can carry the party. */
    /** @param  array<int, array{lat: float, lng: float}>  $stops  extra stops, in order */
    public function estimateAll(float $pLat, float $pLng, float $dLat, float $dLng, ?CarbonInterface $at = null, int $passengers = 1, int $luggage = 0, array $stops = []): array
    {
        [$pickup, $dropoff] = $this->geo->assertTripAllowed($pLat, $pLng, $dLat, $dLng);
        $this->geo->assertStopsAllowed($pickup, $stops);
        $route = $this->distance->route(self::points($pLat, $pLng, $stops, $dLat, $dLng));

        $configs = FareConfig::query()->where('service_area_id', $pickup->service->id)->where('is_active', true)->get()->keyBy('vehicle_type');
        $types = VehicleType::query()->rides()->where('is_active', true)->whereIn('key', $configs->keys())
            ->where('max_passengers', '>=', $passengers)->where('max_luggage', '>=', $luggage)
            ->orderBy('sort_order')->get();

        return $types->map(fn (VehicleType $t) => $this->withHold($pickup->service,
            $this->calculate($pickup, $dropoff, $t->key, $route->distanceM, $route->durationS, 0, $at ?? now(), stopCount: count($stops))) + [
                'display_name' => $t->name,
                'description' => $t->description,
                'max_passengers' => $t->max_passengers,
                'max_luggage' => $t->max_luggage,
                'icon_url' => $t->icon_url,
            ])->values()->all();
    }

    /** Quote for one vehicle type. Also returns the geo contexts so the caller can store them. */
    public function estimate(float $pLat, float $pLng, float $dLat, float $dLng, string $vehicleType, ?CarbonInterface $at = null, int $passengers = 1, int $luggage = 0, array $stops = []): array
    {
        [$pickup, $dropoff] = $this->geo->assertTripAllowed($pLat, $pLng, $dLat, $dLng);
        $this->geo->assertStopsAllowed($pickup, $stops);

        $type = VehicleType::query()->rides()->where('key', $vehicleType)->where('is_active', true)->first();
        if (! $type) {
            throw ValidationException::withMessages(['vehicle_type' => ['Unknown vehicle type.']]);
        }
        if ($passengers > $type->max_passengers) {
            throw ValidationException::withMessages(['passengers' => ["{$type->name} carries up to {$type->max_passengers} passengers."]]);
        }
        if ($luggage > $type->max_luggage) {
            throw ValidationException::withMessages(['luggage' => ["{$type->name} takes up to {$type->max_luggage} pieces of luggage."]]);
        }

        $route = $this->distance->route(self::points($pLat, $pLng, $stops, $dLat, $dLng));

        return $this->withHold($pickup->service, $this->calculate($pickup, $dropoff, $vehicleType, $route->distanceM, $route->durationS, 0, $at ?? now(), stopCount: count($stops)))
            + ['pickup_context' => $pickup, 'dropoff_context' => $dropoff];
    }

    /**
     * New estimate for a trip whose stops are changing (mid-ride). Priced at the time of the original
     * request with the multiplier locked then; a fixed route no longer applies once there are stops.
     */
    public function requote(Trip $trip, array $stops): array
    {
        [$pickup, $dropoff] = $this->geo->assertTripAllowed($trip->pickup_lat, $trip->pickup_lng, $trip->dropoff_lat, $trip->dropoff_lng);
        $this->geo->assertStopsAllowed($pickup, $stops);
        $route = $this->distance->route(self::points($trip->pickup_lat, $trip->pickup_lng, $stops, $trip->dropoff_lat, $trip->dropoff_lng));
        $lock = $trip->fare_breakdown['lock'] ?? [];
        $fixed = $stops ? null : ($lock['fixed_rule_id'] ?? null);

        return $this->withHold($pickup->service, $this->calculate($pickup, $dropoff, $trip->vehicle_type, $route->distanceM, $route->durationS, 0,
            $trip->requested_at ?? now(), $fixed ? null : ($lock['multiplier'] ?? null), $fixed, stopCount: count($stops)))
            + ['route' => $route];
    }

    /**
     * Shop delivery fee, store → customer, from the area's "delivery" rate card (same engine as rides: multipliers,
     * minimum, tax, rounding). Returns the quote plus the route used.
     */
    public function deliveryQuote(GeoContext $store, GeoContext $dropoff, ?CarbonInterface $at = null): array
    {
        $route = $this->distance->route([[$store->lat, $store->lng], [$dropoff->lat, $dropoff->lng]]);

        return $this->calculate($store, $dropoff, 'delivery', $route->distanceM, $route->durationS, 0, $at ?? now()) + ['route' => $route];
    }

    /** [[lat, lng], …] for pickup, stops…, drop-off. */
    public static function points(float $pLat, float $pLng, array $stops, float $dLat, float $dLng): array
    {
        return array_merge([[$pLat, $pLng]], array_map(fn ($s) => [(float) $s['lat'], (float) $s['lng']], array_values($stops)), [[$dLat, $dLng]]);
    }

    /** Amount held on a card at request: estimate × (1 + hold buffer), rounded up like fares. */
    private function withHold(ServiceArea $area, array $quote): array
    {
        $buffer = (float) ($area->hold_buffer_rate ?? config('pricing.hold_buffer_rate', 0.20));
        $quote['hold_amount'] = $this->roundUp($quote['fare'] * (1 + $buffer), (float) ($area->rounding_increment ?? 0.5));

        return $quote;
    }

    /** Final fare from actual trip metrics, honouring the multiplier / fixed route locked at request time. */
    public function finalFare(Trip $trip, int $distanceM, int $durationS, int $waitS): array
    {
        $pickup = $this->geo->resolve($trip->pickup_lat, $trip->pickup_lng);
        $dropoff = $this->geo->resolve($trip->dropoff_lat, $trip->dropoff_lng);
        $pickup = $this->withService($pickup, $trip);

        $lock = $trip->fare_breakdown['lock'] ?? [];

        return $this->calculate($pickup, $dropoff, $trip->vehicle_type, $distanceM, $durationS, $waitS,
            $trip->requested_at ?? now(), $lock['multiplier'] ?? null, $trip->stopCount() ? null : ($lock['fixed_rule_id'] ?? null), stopCount: $trip->stopCount());
    }

    /** Cancellation charge for a trip, split into tax / commission like a fare. */
    public function cancellationCharge(Trip $trip): array
    {
        $area = ServiceArea::query()->find($trip->service_area_id);
        $cfg = $area ? FareConfig::for($area->id, $trip->vehicle_type) : null;
        $amount = (float) ($cfg?->cancellation_fee ?? 0);
        if (! $area || $amount <= 0) {
            return ['total' => 0.0, 'subtotal' => 0.0, 'tax' => 0.0, 'platform_fee' => 0.0, 'driver_earnings' => 0.0];
        }

        return $this->split($area, $amount, round: false);
    }

    // ------------------------------------------------------------------ core

    private function calculate(
        GeoContext $pickup, GeoContext $dropoff, string $vehicleType, int $distanceM, int $durationS, int $waitS,
        CarbonInterface $at, ?float $lockedMultiplier = null, ?int $lockedFixedRuleId = null, int $stopCount = 0,
    ): array {
        $area = $pickup->service;
        $cfg = FareConfig::for($area->id, $vehicleType);
        if (! $cfg) {
            throw ValidationException::withMessages(['vehicle_type' => ["This vehicle type is not available in {$area->name}."]]);
        }

        $local = $at->copy()->setTimezone($area->timezone ?: config('app.timezone'));
        $rules = $this->rules($area, $vehicleType);
        $lines = [];

        $km = $distanceM / 1000;
        $min = $durationS / 60;

        // ---- fixed route? (A-to-B flat fares only: a ride with stops is metered along its route.)
        $fixed = $lockedFixedRuleId
            ? $rules->firstWhere('id', $lockedFixedRuleId)
            : ($lockedMultiplier === null && $stopCount === 0 ? $this->matchFixedRoute($rules, $pickup, $dropoff, $at, $local) : null);

        if ($fixed) {
            $fare = (float) $fixed->amount;
            $multiplier = 1.0;
            $lines[] = ['code' => 'fixed_route', 'label' => $fixed->name, 'amount' => round($fare, 2)];
        } else {
            $timeMult = $lockedMultiplier === null ? $this->maxMultiplier($rules, FareRuleKind::TimeMultiplier, $at, $local) : 1.0;
            $surge = $lockedMultiplier === null ? $this->maxMultiplier($rules, FareRuleKind::Surge, $at, $local) : 1.0;
            // Automatic surge (busy now): only for rides priced for about now, never for bookings later.
            if ($lockedMultiplier === null && abs($at->diffInMinutes(now(), true)) <= 10) {
                $surge = max($surge, app(AutoSurgeService::class)->multiplier($area->id));
            }
            $multiplier = $lockedMultiplier ?? round($timeMult * $surge, 2);

            $distanceCost = round($km * $cfg->per_km, 2);
            $timeCost = round($min * $cfg->per_minute, 2);
            $lines[] = ['code' => 'base', 'label' => 'Base fare', 'amount' => round($cfg->base_fare, 2)];
            $lines[] = ['code' => 'distance', 'label' => sprintf('Distance (%.1f km)', $km), 'amount' => $distanceCost];
            $lines[] = ['code' => 'time', 'label' => sprintf('Time (%d min)', (int) round($min)), 'amount' => $timeCost];

            $metered = $cfg->base_fare + $distanceCost + $timeCost;
            if ($multiplier > 1.0) {
                $extra = round($metered * ($multiplier - 1), 2);
                $lines[] = ['code' => 'multiplier', 'label' => sprintf('Demand ×%.2f', $multiplier), 'amount' => $extra];
                $metered += $extra;
            }
            if ($metered < $cfg->minimum_fare) {
                $lines[] = ['code' => 'minimum', 'label' => 'Minimum fare adjustment', 'amount' => round($cfg->minimum_fare - $metered, 2)];
                $metered = $cfg->minimum_fare;
            }
            $fare = $metered;
        }

        // ---- waiting
        $waitMin = max(0, (int) floor($waitS / 60) - $cfg->free_wait_minutes);
        if ($waitMin > 0 && $cfg->wait_per_minute > 0) {
            $wait = round($waitMin * $cfg->wait_per_minute, 2);
            $lines[] = ['code' => 'waiting', 'label' => "Waiting ({$waitMin} min)", 'amount' => $wait];
            $fare += $wait;
        }

        // ---- zone fees (not on fixed routes — those are all-inclusive)
        if (! $fixed) {
            foreach ($rules->where('kind', FareRuleKind::ZoneFee) as $rule) {
                if (! $rule->activeAt($at, $local) || ! $rule->pickup_zone_id) {
                    continue;
                }
                $hit = match ($rule->zone_applies_to ?? 'either') {
                    'pickup' => $pickup->inZone($rule->pickup_zone_id),
                    'dropoff' => $dropoff->inZone($rule->pickup_zone_id),
                    default => $pickup->inZone($rule->pickup_zone_id) || $dropoff->inZone($rule->pickup_zone_id),
                };
                if ($hit) {
                    $lines[] = ['code' => 'zone_fee', 'label' => $rule->name, 'amount' => round((float) $rule->amount, 2)];
                    $fare += (float) $rule->amount;
                }
            }
        }

        // ---- extra stops (the longer route is already in distance/time)
        if ($stopCount > 0 && $cfg->stop_fee > 0) {
            $stopFee = round($stopCount * $cfg->stop_fee, 2);
            $lines[] = ['code' => 'stops', 'label' => $stopCount === 1 ? 'Extra stop' : "Extra stops ({$stopCount})", 'amount' => $stopFee];
            $fare += $stopFee;
        }

        // ---- booking fee
        if ($cfg->booking_fee > 0) {
            $lines[] = ['code' => 'booking_fee', 'label' => 'Booking fee', 'amount' => round($cfg->booking_fee, 2)];
            $fare += $cfg->booking_fee;
        }

        $money = $this->split($area, $fare);
        if ($money['rounding'] != 0.0) {
            $lines[] = ['code' => 'rounding', 'label' => 'Rounding', 'amount' => $money['rounding']];
        }
        if ($money['tax'] > 0) {
            $lines[] = ['code' => 'tax', 'label' => sprintf('%s %s%%', $area->tax_label ?: 'Tax', rtrim(rtrim(number_format($area->tax_rate * 100, 2), '0'), '.')), 'amount' => $money['tax']];
        }

        return [
            'vehicle_type' => $vehicleType,
            'service_area_id' => $area->id,
            'service_area' => $area->code,
            'currency' => $area->currency ?: config('pricing.currency', 'BBD'),
            'distance_m' => $distanceM,
            'duration_s' => $durationS,
            'fare' => $money['total'],
            'subtotal' => $money['subtotal'],
            'tax' => $money['tax'],
            'platform_fee' => $money['platform_fee'],
            'driver_earnings' => $money['driver_earnings'],
            'surge_multiplier' => $multiplier,
            'pickup_zone_id' => $pickup->primaryZone()?->id,
            'dropoff_zone_id' => $dropoff->primaryZone()?->id,
            'breakdown' => [
                'lines' => $lines,
                'total' => $money['total'],
                'lock' => ['multiplier' => $fixed ? null : $multiplier, 'fixed_rule_id' => $fixed?->id],
            ],
        ];
    }

    /**
     * $net is ex-tax. Adds tax, rounds the gross UP to the area increment (when $round), then re-derives
     * subtotal/tax from the rounded total.
     *
     * @return array{total: float, subtotal: float, tax: float, platform_fee: float, driver_earnings: float, rounding: float}
     */
    private function split(ServiceArea $area, float $net, bool $round = true): array
    {
        $taxRate = (float) ($area->tax_rate ?? 0);
        $gross = $net * (1 + $taxRate);
        $total = $round ? $this->roundUp($gross, (float) ($area->rounding_increment ?? config('pricing.rounding', 0.5))) : round($gross, 2);

        $subtotal = round($total / (1 + $taxRate), 2);
        $tax = round($total - $subtotal, 2);
        $platformFee = round($subtotal * (float) ($area->commission_rate ?? 0), 2);

        return [
            'total' => $total,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'platform_fee' => $platformFee,
            'driver_earnings' => round($subtotal - $platformFee, 2),
            // Rounding expressed ex-tax so the visible lines + tax add up to the total.
            'rounding' => round($subtotal - round($net, 2), 2),
        ];
    }

    private function rules(ServiceArea $area, string $vehicleType): Collection
    {
        return FareRule::query()->where('service_area_id', $area->id)->where('is_active', true)
            ->orderByDesc('priority')->orderBy('id')->get()
            ->filter(fn (FareRule $r) => $r->appliesToVehicle($vehicleType))->values();
    }

    private function maxMultiplier(Collection $rules, FareRuleKind $kind, CarbonInterface $at, CarbonInterface $local): float
    {
        return (float) $rules->where('kind', $kind)
            ->filter(fn (FareRule $r) => $r->activeAt($at, $local) && $r->multiplier > 0)
            ->max('multiplier') ?: 1.0;
    }

    private function matchFixedRoute(Collection $rules, GeoContext $pickup, GeoContext $dropoff, CarbonInterface $at, CarbonInterface $local): ?FareRule
    {
        return $rules->where('kind', FareRuleKind::FixedRoute)->first(function (FareRule $r) use ($pickup, $dropoff, $at, $local) {
            if (! $r->activeAt($at, $local) || ! $r->pickup_zone_id || ! $r->dropoff_zone_id || $r->amount === null) {
                return false;
            }
            $forward = $pickup->inZone($r->pickup_zone_id) && $dropoff->inZone($r->dropoff_zone_id);
            $reverse = $r->bidirectional && $pickup->inZone($r->dropoff_zone_id) && $dropoff->inZone($r->pickup_zone_id);

            return $forward || $reverse;
        });
    }

    /** If an area was edited since the request, keep pricing against the trip's original service area. */
    private function withService(GeoContext $ctx, Trip $trip): GeoContext
    {
        if ($ctx->service && $ctx->service->id === $trip->service_area_id) {
            return $ctx;
        }
        $area = ServiceArea::query()->find($trip->service_area_id);

        return $area ? new GeoContext($ctx->lat, $ctx->lng, $area, $ctx->zones, null) : $ctx;
    }

    private function roundUp(float $amount, float $step): float
    {
        if ($step <= 0) {
            return round($amount, 2);
        }
        // Small epsilon so 10.000000001 doesn't round up to 10.50.
        return round(ceil(round($amount / $step, 6)) * $step, 2);
    }
}
