<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A driver invited to a fleet (invited | active | declined | removed) and the fleet cars they may drive. */
class FleetDriver extends Model
{
    public const INVITED = 'invited';
    public const ACTIVE = 'active';
    public const DECLINED = 'declined';
    public const REMOVED = 'removed';

    protected $fillable = ['fleet_id', 'driver_id', 'phone', 'name', 'status', 'invited_by', 'responded_at', 'removed_at'];

    protected $attributes = ['status' => self::INVITED];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'fleet_driver_vehicle');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function displayName(): string
    {
        return $this->driver?->name ?: ($this->name ?: $this->phone);
    }
}
