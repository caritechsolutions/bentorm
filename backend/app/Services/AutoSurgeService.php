<?php

namespace App\Services;

use App\Models\AutoSurgeSetting;
use App\Models\DriverProfile;
use App\Models\ServiceArea;
use App\Models\Trip;
use App\Support\Features;
use Illuminate\Support\Facades\Cache;

/**
 * Raises fares when riders outnumber free drivers in an area, and drops back as drivers free up. Worked out every
 * minute; a booking keeps the multiplier it was quoted (locked at request, like rule-based surge).
 */
class AutoSurgeService
{
    public function __construct(private readonly DriverLocationService $locations) {}

    /** Scheduler, every minute. Returns area id => multiplier. */
    public function compute(): array
    {
        $out = [];
        $live = collect($this->locations->allLive())->filter(fn ($d) => ($d['state'] ?? 'idle') === 'idle');
        $areaOf = DriverProfile::query()->whereIn('user_id', $live->pluck('driver_id'))->pluck('service_area_id', 'user_id');
        foreach (ServiceArea::query()->where('type', 'service')->where('is_active', true)->get() as $area) {
            $s = AutoSurgeSetting::query()->firstOrCreate(['service_area_id' => $area->id]);
            $demand = Trip::query()->rides()->where('service_area_id', $area->id)->where('requested_at', '>=', now()->subMinutes($s->window_minutes))->count();
            $supply = $live->filter(fn ($d) => (int) ($areaOf[$d['driver_id']] ?? 0) === $area->id)->count();
            $m = Features::on('auto_surge') ? $s->multiplierFor($demand, $supply) : 1.0;
            $s->forceFill(['demand' => $demand, 'supply' => $supply, 'current_multiplier' => $m, 'computed_at' => now()])->save();
            Cache::put('auto_surge:'.$area->id, $m, now()->addMinutes(3)); // stale after 3 min → back to 1
            $out[$area->id] = $m;
        }

        return $out;
    }

    /** The automatic multiplier for an area now (1 when off or not worked out recently). */
    public function multiplier(?int $areaId): float
    {
        return $areaId && Features::on('auto_surge') ? (float) Cache::get('auto_surge:'.$areaId, 1.0) : 1.0;
    }
}
