<?php

namespace App\Models;

use App\Enums\FleetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A taxi owner's fleet: cars other drivers drive. Run from the fleet portal (/fleet) by its owner and managers;
 * its cars can be driven only once an admin approves the fleet (and each car and its documents).
 */
class Fleet extends Model
{
    protected $fillable = ['name', 'slug', 'service_area_id', 'phone', 'email', 'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => FleetStatus::class,
            'approved_at' => 'datetime',
            'bank_account_number' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Fleet $f) {
            if (blank($f->slug)) {
                $base = Str::slug($f->name) ?: 'fleet';
                $slug = $base;
                for ($i = 2; self::query()->where('slug', $slug)->exists(); $i++) {
                    $slug = "{$base}-{$i}";
                }
                $f->slug = $slug;
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'fleet_users')->withPivot('role')->withTimestamps();
    }

    public function members(): HasMany
    {
        return $this->hasMany(FleetMember::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(FleetDriver::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function cash(): HasMany
    {
        return $this->hasMany(FleetCash::class);
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function isApproved(): bool
    {
        return $this->status === FleetStatus::Approved;
    }

    /** Cash each driver is holding for the fleet: [driver_id => amount]. */
    public function cashHeld(): array
    {
        return $this->cash()->selectRaw('driver_id, ROUND(SUM(amount), 2) as held')->groupBy('driver_id')
            ->pluck('held', 'driver_id')->map(fn ($v) => (float) $v)->all();
    }
}
