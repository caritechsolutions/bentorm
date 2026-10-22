<?php

namespace App\Filament\Support;

use BackedEnum;

/** Shared display helpers for the admin panel. */
final class Ui
{
    public static function color(mixed $state): string
    {
        $v = $state instanceof BackedEnum ? $state->value : (string) $state;

        return match ($v) {
            'approved', 'active', 'completed', 'paid', 'captured', 'service', 'ok' => 'success',
            'pending', 'pending_payment', 'requested', 'authorized', 'expiring_soon', 'zone' => 'warning',
            'accepted', 'arrived', 'in_progress', 'admin' => 'info',
            'rejected', 'suspended', 'failed', 'expired', 'blocked', 'cancelled', 'no_drivers', 'missing', 'voided', 'refunded' => 'danger',
            default => 'gray',
        };
    }

    public static function label(mixed $state): string
    {
        $v = $state instanceof BackedEnum ? $state->value : (string) $state;

        return ucfirst(str_replace('_', ' ', $v));
    }

    public static function money(?float $amount, ?string $currency = 'BBD'): string
    {
        return $amount === null ? '—' : ($currency ?: 'BBD').' '.number_format($amount, 2);
    }

    /** @return array<string, string> */
    public static function features(): array
    {
        return config('bentorm.vehicle_features', []);
    }
}
