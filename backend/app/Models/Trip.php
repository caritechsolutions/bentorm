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
        'rider_id', 'order_id', 'driver_id', 'vehicle_id', 'service_area_id', 'pickup_zone_id', 'dropoff_zone_id', 'vehicle_type', 'status',
        'pickup_lat', 'pickup_lng', 'pickup_address', 'dropoff_lat', 'dropoff_lng', 'dropoff_address', 'stops', 'stop_change',
        'pin', 'pin_attempts', 'pin_failed_at', 'pin_verified_at',
        'estimated_distance_m', 'estimated_duration_s', 'estimated_fare',
        'actual_distance_m', 'actual_duration_s', 'wait_duration_s', 'currency', 'subtotal', 'tax_amount', 'final_fare',
        'platform_fee', 'driver_earnings', 'fare_breakdown', 'surge_multiplier',
        'payment_method', 'payment_status', 'amount_due', 'payment_method_id', 'hold_amount',
        'passengers', 'luggage', 'required_features', 'rider_note',
        'requested_at', 'scheduled_at', 'accepted_at', 'arrived_at', 'started_at', 'completed_at', 'cancelled_at',
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
            'estimated_fare' => 'float',
            'subtotal' => 'float',
            'tax_amount' => 'float',
            'final_fare' => 'float',
            'platform_fee' => 'float',
            'driver_earnings' => 'float',
            'amount_due' => 'float',
            'hold_amount' => 'float',
            'luggage' => 'integer',
            'required_features' => 'array',
            'cancellation_fee' => 'float',
            'stops' => 'array',
            'stop_change' => 'array',
            'pin_attempts' => 'integer',
            'pin_failed_at' => 'datetime',
            'pin_verified_at' => 'datetime',
            'surge_multiplier' => 'float',
            'fare_breakdown' => 'array',
            'passengers' => 'integer',
            'requested_at' => 'datetime',
            'scheduled_at' => 'datetime',
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

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(\App\Models\PaymentMethod::class, 'payment_method_id');
    }

    /** The authorisation placed when the trip was requested, if any. */
    public function hold(): ?Payment
    {
        return $this->payments()->where('purpose', 'hold')->latest('id')->first();
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

    /** The shop order this trip delivers (null for rides). */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isDelivery(): bool
    {
        return $this->order_id !== null;
    }

    /** Rides only (deliveries are trips too, but never shown as the customer's rides). */
    public function scopeRides($query)
    {
        return $query->whereNull('trips.order_id');
    }

    /**
     * What the driver would take home from the estimate (ex VAT, after commission) — shown on offers,
     * before driver_earnings exists. Mirrors PricingService: subtotal = fare / (1 + tax), driver = subtotal × (1 − commission).
     */
    public function estimatedDriverEarnings(): ?float
    {
        if ($this->order_id) {
            return $this->order?->driver_earnings;
        }
        if ($this->estimated_fare === null) {
            return null;
        }
        $area = $this->relationLoaded('serviceArea') ? $this->serviceArea : $this->serviceArea()->first();
        $tax = (float) ($area?->tax_rate ?? 0);
        $commission = (float) ($area?->commission_rate ?? 0);

        return round(((float) $this->estimated_fare / (1 + $tax)) * (1 - $commission), 2);
    }

    /** Route points in order: pickup, stops…, drop-off — [[lat, lng], …]. */
    public function routePoints(): array
    {
        return array_merge(
            [[(float) $this->pickup_lat, (float) $this->pickup_lng]],
            array_map(fn ($s) => [(float) $s['lat'], (float) $s['lng']], $this->stops ?? []),
            [[(float) $this->dropoff_lat, (float) $this->dropoff_lng]],
        );
    }

    public function stopCount(): int
    {
        return count($this->stops ?? []);
    }
}
