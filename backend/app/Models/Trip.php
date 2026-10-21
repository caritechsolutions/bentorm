<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasUlids;

    protected $fillable = [
        'rider_id', 'driver_id', 'vehicle_id', 'vehicle_type', 'status',
        'pickup_lat', 'pickup_lng', 'pickup_address', 'dropoff_lat', 'dropoff_lng', 'dropoff_address',
        'estimated_distance_m', 'estimated_duration_s', 'estimated_fare',
        'actual_distance_m', 'actual_duration_s', 'final_fare', 'currency', 'fare_breakdown', 'surge_multiplier',
        'payment_method', 'payment_status', 'passengers', 'rider_note',
        'requested_at', 'accepted_at', 'arrived_at', 'started_at', 'completed_at', 'cancelled_at',
        'cancelled_by', 'cancellation_reason', 'cancellation_fee',
    ];

    /** Only public_id is a ULID; the primary key stays an auto-increment integer. */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'dropoff_lat' => 'float',
            'dropoff_lng' => 'float',
            'estimated_fare' => 'decimal:2',
            'final_fare' => 'decimal:2',
            'cancellation_fee' => 'decimal:2',
            'surge_multiplier' => 'decimal:2',
            'fare_breakdown' => 'array',
            'passengers' => 'integer',
            'requested_at' => 'datetime',
            'accepted_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(TripOffer::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(TripLocation::class)->orderBy('recorded_at');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function involves(User $user): bool
    {
        return $this->rider_id === $user->id || $this->driver_id === $user->id;
    }
}
