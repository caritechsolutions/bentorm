<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shop order: one customer, one store, card only. */
class Order extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'items_subtotal' => 'float', 'items_tax' => 'float', 'delivery_fee' => 'float', 'delivery_fee_ex_tax' => 'float',
            'service_fee' => 'float', 'total' => 'float', 'commission' => 'float', 'store_payout' => 'float',
            'delivery_platform_share' => 'float', 'driver_earnings' => 'float',
            'dropoff_lat' => 'float', 'dropoff_lng' => 'float',
            'breakdown' => 'array', 'change_request' => 'array',
            'placed_at' => 'datetime', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'ready_by' => 'datetime',
            'ready_at' => 'datetime', 'cancelled_at' => 'datetime',
            'driver_requested_at' => 'datetime', 'driver_alert_at' => 'datetime', 'picked_up_at' => 'datetime', 'delivered_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Short number customers and stores read out ("order 1042"). */
    public function number(): string
    {
        return (string) (1000 + $this->id);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Delivery jobs for this order (a new one is made if a driver cancels or nobody takes it). */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /** The delivery job still in play (looking for a driver, or a driver has it). */
    public function activeDelivery(): ?Trip
    {
        return $this->deliveries()->whereIn('status', array_map(fn ($s) => $s->value, \App\Enums\TripStatus::active()))->latest('id')->first();
    }

    /** The newest delivery job, finished or not. */
    public function latestDelivery(): ?Trip
    {
        return $this->deliveries()->latest('id')->first();
    }

    public function hold(): ?Payment
    {
        return $this->payments()->where('purpose', 'hold')->latest('id')->first();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function hasPendingChange(): bool
    {
        return ($this->change_request['status'] ?? null) === 'pending';
    }
}
