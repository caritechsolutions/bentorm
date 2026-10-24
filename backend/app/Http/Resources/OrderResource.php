<?php

namespace App\Http\Resources;

use App\Models\Order;
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
                'service_fee' => $this->service_fee, 'total' => $this->total,
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
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(), 'placed_at' => $this->placed_at?->toIso8601String(),
                'expires_at' => $this->expires_at?->toIso8601String(), 'accepted_at' => $this->accepted_at?->toIso8601String(),
                'ready_by' => $this->ready_by?->toIso8601String(), 'ready_at' => $this->ready_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
        ];
    }
}
