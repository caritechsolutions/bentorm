<?php

namespace App\Models;

use App\Enums\DriverStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DriverProfile extends Model
{
    protected $fillable = [
        'user_id', 'service_area_id', 'licence_number', 'licence_expiry', 'psv_permit_number',
        'status', 'status_note', 'is_online', 'current_vehicle_id', 'rating_avg', 'rating_count',
        'completed_trips', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'is_online' => 'boolean',
            'licence_expiry' => 'date',
            'approved_at' => 'datetime',
            'rating_avg' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'current_vehicle_id');
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isApproved(): bool
    {
        return $this->status === DriverStatus::Approved;
    }
}
