<?php

namespace App\Models;

use App\Enums\FareRuleKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FareRule extends Model
{
    protected $fillable = [
        'service_area_id', 'vehicle_type', 'name', 'kind', 'multiplier', 'amount', 'days', 'start_time', 'end_time',
        'starts_at', 'ends_at', 'pickup_zone_id', 'dropoff_zone_id', 'zone_applies_to', 'bidirectional', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => FareRuleKind::class,
            'multiplier' => 'float',
            'amount' => 'float',
            'days' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'bidirectional' => 'boolean',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function pickupZone(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class, 'pickup_zone_id');
    }

    public function dropoffZone(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class, 'dropoff_zone_id');
    }

    public function appliesToVehicle(string $vehicleType): bool
    {
        return $this->vehicle_type === null || $this->vehicle_type === $vehicleType;
    }

    /**
     * Whether the rule is in effect at $at. $local is the same instant in the service area's timezone,
     * used for day-of-week and time-of-day windows (which may wrap midnight, e.g. 22:00–04:00).
     */
    public function activeAt(CarbonInterface $at, CarbonInterface $local): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_at && $at->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at && $at->gte($this->ends_at)) {
            return false;
        }
        if (! $this->start_time || ! $this->end_time) {
            return $this->dayMatches((int) $local->dayOfWeek);
        }

        $now = $local->format('H:i:s');
        // MySQL returns HH:MM:SS, SQLite may return HH:MM — normalise before comparing strings.
        $start = str_pad(substr($this->start_time, 0, 8), 8, ':00');
        $end = str_pad(substr($this->end_time, 0, 8), 8, ':00');

        if ($start <= $end) {
            return $now >= $start && $now < $end && $this->dayMatches((int) $local->dayOfWeek);
        }

        // Wraps midnight: the window belongs to the day it started on.
        if ($now >= $start) {
            return $this->dayMatches((int) $local->dayOfWeek);
        }
        if ($now < $end) {
            return $this->dayMatches(((int) $local->dayOfWeek + 6) % 7);
        }

        return false;
    }

    private function dayMatches(int $day): bool
    {
        return empty($this->days) || in_array($day, array_map('intval', $this->days), true);
    }
}
