<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\NavigationUsage;
use App\Models\Trip;
use App\Models\User;

/**
 * In-app turn-by-turn navigation (Google Navigation SDK) is billed per destination, so the admin can switch it
 * on or off and cap it per month. Each start is recorded; past the cap (or when off) the driver app opens
 * Google Maps instead, which costs nothing.
 */
class NavigationPolicy
{
    /** Google's free allowance per month (pricing from May 2025 — check the Google Cloud billing page). */
    public const FREE_PER_MONTH = 1000;

    public function enabled(): bool
    {
        return (bool) AppSetting::get('navigation.in_app', false);
    }

    public function monthlyLimit(): ?int
    {
        $v = AppSetting::get('navigation.monthly_limit');

        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    public function usedThisMonth(): int
    {
        return NavigationUsage::query()->where('created_at', '>=', now()->startOfMonth())->count();
    }

    public function usedLastMonth(): int
    {
        return NavigationUsage::query()
            ->where('created_at', '>=', now()->subMonthNoOverflow()->startOfMonth())
            ->where('created_at', '<', now()->startOfMonth())->count();
    }

    /** Null when in-app navigation can be used; otherwise why not ('off' | 'monthly_limit'). */
    public function blockedReason(): ?string
    {
        if (! $this->enabled()) {
            return 'off';
        }
        $limit = $this->monthlyLimit();

        return $limit !== null && $this->usedThisMonth() >= $limit ? 'monthly_limit' : null;
    }

    /** The driver app asks before each in-app navigation; an allowed start is counted. */
    public function start(User $driver, ?Trip $trip, string $destination): ?string
    {
        $reason = $this->blockedReason();
        if ($reason === null) {
            NavigationUsage::query()->create(['trip_id' => $trip?->id, 'driver_id' => $driver->id, 'destination' => $destination]);
        }

        return $reason;
    }
}
