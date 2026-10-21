<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FareConfig extends Model
{
    protected $fillable = [
        'vehicle_type', 'display_name', 'description', 'currency', 'base_fare', 'per_km', 'per_minute',
        'minimum_fare', 'booking_fee', 'cancellation_fee', 'surge_multiplier', 'max_passengers',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'base_fare' => 'decimal:2',
            'per_km' => 'decimal:2',
            'per_minute' => 'decimal:2',
            'minimum_fare' => 'decimal:2',
            'booking_fee' => 'decimal:2',
            'cancellation_fee' => 'decimal:2',
            'surge_multiplier' => 'decimal:2',
            'max_passengers' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function forType(string $vehicleType): ?self
    {
        return static::query()->where('vehicle_type', $vehicleType)->where('is_active', true)->first();
    }
}
