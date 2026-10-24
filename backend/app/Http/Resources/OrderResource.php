<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Order;
use App\Services\DriverLocationService;
use App\Services\OrderService;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hold = $this->relationLoaded('payments') ? $this->payments->where('purpose', 'hold')->sortByDesc('id')->first() : $this->hold();

        return [
            'id' => $this->public_id,
            'number' => $this->number(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'store' => [
                'slug' => $this->store?->slug, 'name' => $this->store?->name, 'phone' => $this->store?->phone,
                'logo_url' => $this->store?->logoUrl(), 'address' => $this->store?->address,
                'lat' => $this->store?->lat, 'lng' => $this->store?->lng,
            ],
            'items' => $this->items->map(fn (OrderItem $i) => [
                'id' => $i->id, 'name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price,
                'line_total' => $i->line_total, 'options' => $i->options ?? [], 'options_text' => $i->optionsText(), 'note' => $i->note, 'removed' => $i->removed,
            ])->values(),
            'currency' => $this->currency,
            'totals' => [
                'items' => $this->items_subtotal, 'items_tax' => $this->items_tax, 'delivery' => $this->delivery_fee,
                'service_fee' => $this->service_fee, 'tip' => $this->tip, 'discount' => $this->discount, 'credit' => $this->credit, 'total' => $this->total,
            ],
            'lines' => $this->breakdown['lines'] ?? [],
            'dropoff' => ['lat' => $this->dropoff_lat, 'lng' => $this->dropoff_lng, 'address' => $this->dropoff_address],
            'distance_m' => $this->distance_m,
            'note' => $this->note,
            'prep_minutes' => $this->prep_minutes,
            'change_request' => $this->change_request,
            'decline_reason' => $this->decline_reason,
            'cancelled_by' => $this->cancelled_by,
            'payment' => [
                'status' => $this->payment_status,
                'card' => $this->card ? ['brand' => $this->card->brand, 'last4' => $this->card->last4] : null,
                'redirect_url' => $hold?->redirect_url,
            ],
            'delivery' => $this->delivery($request),
            'can_tip' => ($d = $this->deliveries()->where('status', 'completed')->latest('id')->first()) !== null
                && app(\App\Services\TipService::class)->canTip($d, $request->user()),
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(), 'placed_at' => $this->placed_at?->toIso8601String(),
                'expires_at' => $this->expires_at?->toIso8601String(), 'accepted_at' => $this->accepted_at?->toIso8601String(),
                'ready_by' => $this->ready_by?->toIso8601String(), 'ready_at' => $this->ready_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'picked_up_at' => $this->picked_up_at?->toIso8601String(), 'delivered_at' => $this->delivered_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Where the delivery stands: searching → driver_assigned → at_store → on_the_way → delivered.
     * The customer gets the hand-over PIN and the driver's live position once someone has the job.
     */
    private function delivery(Request $request): ?array
    {
        $isCustomer = $request->user()?->id === $this->customer_id;
        $trip = $this->deliveries()->with(['driver', 'vehicle'])->latest('id')->first();
        $status = match (true) {
            $this->status === OrderStatus::Delivered => 'delivered',
            $this->status === OrderStatus::PickedUp => 'on_the_way',
            $trip && $trip->driver_id && $trip->status === TripStatus::Arrived => 'at_store',
            $trip && $trip->driver_id && $trip->status === TripStatus::Accepted => 'driver_assigned',
            $this->driver_requested_at !== null && $this->resource->isActive() => 'searching',
            default => null,
        };
        $withDriver = in_array($status, ['driver_assigned', 'at_store', 'on_the_way'], true) && $trip?->driver;
        $v = $withDriver ? $trip->vehicle : null;

        return [
            'status' => $status,
            'driver' => $withDriver ? [
                'name' => $trip->driver->firstName(),
                // With in-app calls on, numbers stay private (call through the app).
                'phone' => \App\Support\Features::on('masked_calls') ? null : $trip->driver->phone,
                'vehicle' => $v ? ['make' => $v->make, 'model' => $v->model, 'colour' => $v->colour, 'plate' => $v->plate] : null,
            ] : null,
            'driver_position' => $withDriver ? app(DriverLocationService::class)->position($trip->driver_id) : null,
            // Read to the driver at the door. Only the customer sees it.
            'pin' => $this->when($isCustomer && $this->delivery_pin !== null && $this->resource->isActive() && $this->status !== OrderStatus::PendingPayment, $this->delivery_pin),
            'no_driver_alert' => $this->driver_alert_at !== null && $status === 'searching',
            'can_cancel' => $isCustomer && ($this->status->value === 'placed' || $this->status->value === 'pending_payment' || app(OrderService::class)->customerMayCancelForNoDriver($this->resource)),
        ];
    }
}
