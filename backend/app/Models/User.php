<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'role', 'avatar_path', 'country_code', 'is_active',
        'phone_verified_at', 'last_seen_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Mirror the column defaults so a freshly created model behaves like a reloaded one. */
    protected $attributes = ['role' => 'rider', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'phone_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Only active admins may use the web admin panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() && $this->is_active;
    }

    // ---------------------------------------------------------------- roles

    public function isRider(): bool
    {
        return $this->role === UserRole::Rider;
    }

    public function isDriver(): bool
    {
        return $this->role === UserRole::Driver;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    // ---------------------------------------------------------------- relations

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'driver_id');
    }

    public function ridesAsRider(): HasMany
    {
        return $this->hasMany(Trip::class, 'rider_id');
    }

    public function ridesAsDriver(): HasMany
    {
        return $this->hasMany(Trip::class, 'driver_id');
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function defaultCard(): ?PaymentMethod
    {
        return $this->paymentMethods()->where('status', 'active')->orderByDesc('is_default')->latest('id')->get()
            ->first(fn (PaymentMethod $pm) => $pm->isUsable());
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function ratingsReceived(): HasMany
    {
        return $this->hasMany(Rating::class, 'ratee_id');
    }

    /** The trip this user is currently involved in, if any. */
    public function activeTrip(): ?Trip
    {
        $column = $this->isDriver() ? 'driver_id' : 'rider_id';

        return Trip::query()
            ->where($column, $this->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, TripStatus::active()))
            ->latest('id')
            ->first();
    }

    /** Trips (as rider) with money still owed — blocks new requests. */
    public function outstandingTrips()
    {
        return Trip::query()->where('rider_id', $this->id)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->where('amount_due', '>', 0)
            ->whereIn('status', [TripStatus::Completed->value, TripStatus::Cancelled->value]);
    }
}
