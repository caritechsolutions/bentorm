<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    protected $fillable = [
        'driver_id', 'make', 'model', 'year', 'colour', 'plate', 'seats', 'vehicle_type', 'status', 'documents',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehicleStatus::class,
            'documents' => 'array',
            'year' => 'integer',
            'seats' => 'integer',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function isApproved(): bool
    {
        return $this->status === VehicleStatus::Approved;
    }
}
