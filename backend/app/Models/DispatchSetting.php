<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a ride request reaches drivers (admin → Settings → Driver dispatch).
 *
 *  strategy  waves:     offer to the best [batch_size] drivers; if nobody accepts in time, the next best.
 *            broadcast: offer to every fitting driver in range at once (up to [broadcast_limit]).
 *  ranking   distance:     nearest in a straight line.
 *            eta:          fastest arrival by road (the nearest [eta_candidates] are checked with the routing provider).
 *            longest_idle: the driver who has waited longest for a ride.
 *  radius    starts at radius_start_m and widens by radius_step_m up to radius_max_m. A ring with nobody new in it
 *            widens at once; each round of offers (and each empty search at the widest ring) uses one of max_waves.
 */
class DispatchSetting extends Model
{
    public const STRATEGIES = ['waves' => 'Waves (a few drivers at a time)', 'broadcast' => 'Broadcast (every driver in range)'];

    public const RANKINGS = ['distance' => 'Nearest (straight line)', 'eta' => 'Fastest arrival by road', 'longest_idle' => 'Longest waiting for a ride'];

    protected $fillable = [
        'service_area_id', 'strategy', 'ranking', 'batch_size', 'broadcast_limit', 'offer_ttl_seconds', 'max_waves',
        'radius_start_m', 'radius_step_m', 'radius_max_m', 'eta_candidates',
    ];

    protected function casts(): array
    {
        return [
            'batch_size' => 'integer', 'broadcast_limit' => 'integer', 'offer_ttl_seconds' => 'integer', 'max_waves' => 'integer',
            'radius_start_m' => 'integer', 'radius_step_m' => 'integer', 'radius_max_m' => 'integer', 'eta_candidates' => 'integer',
        ];
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function isDefault(): bool
    {
        return $this->service_area_id === null;
    }

    public function isBroadcast(): bool
    {
        return $this->strategy === 'broadcast';
    }

    /** The settings for a trip: its service area's own, else the default, else built-in values. */
    public static function forTrip(Trip $trip): self
    {
        return self::forArea($trip->service_area_id);
    }

    public static function forArea(?int $serviceAreaId): self
    {
        $rows = self::query()->where(fn ($q) => $q->whereNull('service_area_id')->when($serviceAreaId, fn ($q) => $q->orWhere('service_area_id', $serviceAreaId)))->get();

        return $rows->firstWhere('service_area_id', $serviceAreaId)
            ?? $rows->firstWhere('service_area_id', null)
            ?? new self([
                'strategy' => 'waves', 'ranking' => 'distance',
                'batch_size' => (int) config('dispatch.offer_batch_size', 3), 'broadcast_limit' => 25,
                'offer_ttl_seconds' => (int) config('dispatch.offer_ttl_seconds', 20), 'max_waves' => (int) config('dispatch.max_waves', 4),
                'radius_start_m' => (int) config('dispatch.search_radius_m', 8000), 'radius_step_m' => 0,
                'radius_max_m' => (int) config('dispatch.search_radius_m', 8000), 'eta_candidates' => 10,
            ]);
    }

    /** Search radius for ring n (0-based). */
    public function radiusForWave(int $wave): int
    {
        $max = max($this->radius_max_m, $this->radius_start_m);

        return min($this->radius_start_m + max(0, $wave) * $this->radius_step_m, $max);
    }

    /** Longest a rider can wait before "no drivers", in seconds (every wave sends offers that time out). */
    public function longestWaitSeconds(): int
    {
        return $this->max_waves * $this->offer_ttl_seconds;
    }

    /** One line for the admin list, e.g. "3 km → 12 km (+3 km each time)". */
    public function radiusSummary(): string
    {
        $km = fn (int $m) => rtrim(rtrim(number_format($m / 1000, 1), '0'), '.').' km';
        $last = max($this->radius_max_m, $this->radius_start_m);

        return $this->radius_step_m > 0 && $last > $this->radius_start_m
            ? $km($this->radius_start_m).' → '.$km($last).' (+'.$km($this->radius_step_m).' each time)'
            : $km($this->radius_start_m);
    }
}
