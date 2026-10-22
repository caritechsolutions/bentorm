<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vehicle extends Model
{
    protected $fillable = [
        'driver_id', 'make', 'model', 'year', 'colour', 'plate', 'seats', 'vehicle_type', 'status', 'status_note', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehicleStatus::class,
            'approved_at' => 'datetime',
            'year' => 'integer',
            'seats' => 'integer',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type', 'key');
    }

    public function isApproved(): bool
    {
        return $this->status === VehicleStatus::Approved;
    }
}
