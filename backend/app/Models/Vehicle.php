<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vehicle extends Model
{
    protected $fillable = [
        'driver_id', 'fleet_id', 'make', 'model', 'year', 'colour', 'plate', 'seats', 'luggage_capacity', 'features', 'vehicle_type', 'status', 'status_note', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehicleStatus::class,
            'approved_at' => 'datetime',
            'year' => 'integer',
            'seats' => 'integer',
            'luggage_capacity' => 'integer',
            'features' => 'array',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function isFleetCar(): bool
    {
        return $this->fleet_id !== null;
    }

    /** The driver who has this car selected and is online in it (fleet cars: one at a time), other than $except. */
    public function inUseBy(?int $except = null): ?User
    {
        $profile = DriverProfile::query()->where('current_vehicle_id', $this->id)->where('is_online', true)
            ->when($except, fn ($q) => $q->where('user_id', '!=', $except))->with('user')->first();

        return $profile?->user;
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type', 'key');
    }

    /** Can this vehicle carry the request? */
    public function fits(int $passengers, int $luggage, array $features): bool
    {
        return $this->seats >= $passengers && $this->luggage_capacity >= $luggage
            && array_diff($features, $this->features ?? []) === [];
    }

    public function isApproved(): bool
    {
        return $this->status === VehicleStatus::Approved;
    }
}
