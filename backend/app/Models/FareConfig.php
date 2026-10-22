<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FareConfig extends Model
{
    protected $fillable = [
        'service_area_id', 'vehicle_type', 'base_fare', 'per_km', 'per_minute', 'minimum_fare', 'booking_fee',
        'cancellation_fee', 'free_wait_minutes', 'wait_per_minute', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'base_fare' => 'float', 'per_km' => 'float', 'per_minute' => 'float', 'minimum_fare' => 'float',
            'booking_fee' => 'float', 'cancellation_fee' => 'float', 'wait_per_minute' => 'float',
            'free_wait_minutes' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type', 'key');
    }

    public static function for(int $serviceAreaId, string $vehicleType): ?self
    {
        return static::query()->where('service_area_id', $serviceAreaId)->where('vehicle_type', $vehicleType)
            ->where('is_active', true)->first();
    }
}
