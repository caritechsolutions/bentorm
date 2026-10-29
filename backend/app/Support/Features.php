<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Admin switches and numbers for app features (Settings → App features). The apps read the public part from
 * GET /config → features, so a feature can be turned on or off without a new app build.
 */
final class Features
{
    /** key => default. Booleans are on/off switches; numbers are amounts or minutes. */
    public const DEFAULTS = [
        'saved_places' => true,
        'scheduled_rides' => true,
        'scheduled.min_minutes' => 30,        // earliest a ride can be booked ahead
        'scheduled.max_days' => 7,            // furthest ahead
        'scheduled.dispatch_lead_minutes' => 15, // start looking for a driver this long before pickup
        'tips' => true,
        'tips.window_hours' => 24,            // rides can be tipped this long after they end
        'tips.max_amount' => 200,
        'promos' => true,
        'referrals' => true,
        'referral.referrer_credit' => 10,     // credit for the person who invited
        'referral.referee_credit' => 10,      // credit for the new customer
        'credit.max_share' => 1.0,            // credit can pay up to this share of a ride/order
        'share_trip' => true,
        'sos' => true,
        'chat' => true,
        'masked_calls' => true,               // needs Twilio voice (Settings → Gateways → SMS → Twilio)
        'support' => true,
        'auto_surge' => false,
        'pickup.max_distance_m' => 150,       // driver must be this close to the rider or the pickup to arrive / start (0 = off)
    ];

    /** What the apps may see (no secrets; amounts are shown to customers anyway). */
    public const PUBLIC = [
        'saved_places', 'scheduled_rides', 'scheduled.min_minutes', 'scheduled.max_days', 'tips', 'tips.window_hours', 'tips.max_amount',
        'promos', 'referrals', 'referral.referrer_credit', 'referral.referee_credit', 'share_trip', 'sos', 'chat', 'masked_calls', 'support',
    ];

    public static function on(string $key): bool
    {
        return (bool) AppSetting::get('feature.'.$key, self::DEFAULTS[$key]);
    }

    public static function number(string $key): float
    {
        return (float) AppSetting::get('feature.'.$key, self::DEFAULTS[$key]);
    }

    public static function put(string $key, mixed $value): void
    {
        AppSetting::put('feature.'.$key, is_bool(self::DEFAULTS[$key]) ? (bool) $value : (float) $value);
    }

    /** @return array<string, bool|float|int> */
    public static function forApps(): array
    {
        $out = [];
        foreach (self::PUBLIC as $k) {
            $v = AppSetting::get('feature.'.$k, self::DEFAULTS[$k]);
            $out[$k] = is_bool(self::DEFAULTS[$k]) ? (bool) $v : (is_int(self::DEFAULTS[$k]) ? (int) $v : (float) $v);
        }

        return $out;
    }

    /** Throws a 403 with a friendly message when a feature is switched off. */
    public static function require(string $key, string $what): void
    {
        abort_unless(self::on($key), 403, "{$what} is not available right now.");
    }
}
