<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;


class TripResource extends Resource
{
    public function toArray(Request $request): array
    {
        $isDriverOrAdmin = $request->user() && ($request->user()->isAdmin() || $request->user()->id === $this->driver_id);
        $isRiderOrAdmin = $request->user() && ($request->user()->isAdmin() || $request->user()->id === $this->rider_id);
        $change = $this->stop_change;

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'vehicle_type' => $this->vehicle_type,
            'service_area_id' => $this->service_area_id,
            'rider' => new UserResource($this->whenLoaded('rider')),
            'driver' => new UserResource($this->whenLoaded('driver')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'pickup' => ['lat' => $this->pickup_lat, 'lng' => $this->pickup_lng, 'address' => $this->pickup_address, 'zone_id' => $this->pickup_zone_id],
            'dropoff' => ['lat' => $this->dropoff_lat, 'lng' => $this->dropoff_lng, 'address' => $this->dropoff_address, 'zone_id' => $this->dropoff_zone_id],
            // Extra stops in order; reached_at is set when the driver gets there.
            'stops' => array_map(fn ($s) => ['lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'address' => $s['address'] ?? null, 'reached_at' => $s['reached_at'] ?? null], $this->stops ?? []),
            // A mid-ride change of stops: status pending (driver to confirm) / accepted / declined.
            'stop_change' => $change ? [
                'status' => $change['status'] ?? null,
                'stops' => array_map(fn ($s) => ['lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'address' => $s['address'] ?? null], $change['stops'] ?? []),
                'estimated_fare' => isset($change['estimated_fare']) ? (float) $change['estimated_fare'] : null,
                'requested_at' => $change['requested_at'] ?? null,
                'responded_at' => $change['responded_at'] ?? null,
            ] : null,
            // The rider reads this to the driver, who must enter it to start the trip. Never sent to drivers.
            'pin' => $this->when($isRiderOrAdmin && $this->pin !== null && ($this->isActive() || $this->status?->value === 'pending_payment'), $this->pin),
            'pin_verified' => $this->pin_verified_at !== null,
            'estimate' => [
                'distance_m' => $this->estimated_distance_m,
                'duration_s' => $this->estimated_duration_s,
                'fare' => $this->estimated_fare,
            ],
            'actual' => [
                'distance_m' => $this->actual_distance_m,
                'duration_s' => $this->actual_duration_s,
                'wait_s' => $this->wait_duration_s,
                'fare' => $this->final_fare,
                'subtotal' => $this->subtotal,
                'tax' => $this->tax_amount,
            ],
            'driver_earnings' => $this->when($isDriverOrAdmin, $this->driver_earnings),
            // A trip in a fleet's car: its earnings are paid to the fleet.
            'earnings_paid_to' => $this->when($isDriverOrAdmin && $this->fleet_id, fn () => $this->fleet?->name),
            'tip' => (float) $this->tip_amount,
            // Promo discount and ride credit (paid by Ride Coop): the rider pays `payable`; cash drivers collect it.
            'discount' => (float) $this->discount_amount,
            'credit' => (float) $this->credit_amount,
            'payable' => $this->final_fare !== null ? $this->resource->payable() : null,
            'promo_code' => $this->promo_code_id ? \App\Models\PromoCode::query()->whereKey($this->promo_code_id)->value('code') : null,
            'use_credit' => (bool) $this->use_credit,
            // Safety: chat and in-app calls while a driver is on the way / on the trip (no phone numbers shared).
            'can_chat' => ($isRiderOrAdmin || $isDriverOrAdmin) && app(\App\Services\ChatService::class)->canChat($this->resource),
            'can_call' => ($isRiderOrAdmin || $isDriverOrAdmin) && app(\App\Services\CallService::class)->canCall($this->resource),
            'unread_messages' => $request->user() ? \App\Models\TripMessage::query()->where('trip_id', $this->id)
                ->where('sender_id', '!=', $request->user()->id)->whereNull('read_at')->count() : 0,
            'can_tip' => $isRiderOrAdmin && app(\App\Services\TipService::class)->canTip($this->resource, $request->user()),
            'platform_fee' => $this->when($request->user()?->isAdmin() ?? false, $this->platform_fee),
            'currency' => $this->currency,
            'fare_breakdown' => $this->fare_breakdown ? ['lines' => $this->fare_breakdown['lines'] ?? [], 'total' => $this->fare_breakdown['total'] ?? null] : null,
            'surge_multiplier' => (float) $this->surge_multiplier,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'amount_due' => (float) $this->amount_due,
            'passengers' => $this->passengers,
            'luggage' => $this->luggage,
            'required_features' => $this->required_features ?? [],
            'hold_amount' => $this->hold_amount,
            'card' => $this->whenLoaded('card', fn () => $this->card ? ['id' => $this->card->public_id, 'brand' => $this->card->brand, 'last4' => $this->card->last4] : null),
            // When a card hold needs the rider (3-D Secure), the app opens this URL.
            'payment_action' => $this->paymentAction(),
            'rider_note' => $this->rider_note,
            'cancelled_by' => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_fee' => (float) $this->cancellation_fee,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'timestamps' => [
                'requested_at' => $this->requested_at?->toIso8601String(),
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'arrived_at' => $this->arrived_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
            'ratings' => RatingResource::collection($this->whenLoaded('ratings')),
            // Shop deliveries: what to collect where, for whom. The customer's PIN is never sent to the driver.
            'kind' => $this->order_id ? 'delivery' : 'ride',
            'delivery' => $this->order_id ? $this->deliveryInfo() : null,
        ];
    }

    private function deliveryInfo(): array
    {
        $o = $this->order;
        $store = $o?->store;

        return [
            'order_id' => $o?->public_id,
            'order_number' => $o?->number(),
            'order_status' => $o?->status->value,
            'store' => ['name' => $store?->name, 'phone' => $store?->phone, 'address' => $store?->address],
            'customer_name' => $o?->customer?->firstName(),
            'items' => $o ? $o->items->where('removed', false)->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'options_text' => $i->optionsText()])->values() : [],
            'note' => $o?->note,
            'earnings' => $o?->driver_earnings,
            'picked_up' => $o?->picked_up_at !== null,
        ];
    }

    private function paymentAction(): ?array
    {
        if ($this->status?->value !== 'pending_payment') {
            return null;
        }
        $hold = $this->hold();

        return $hold?->redirect_url ? ['type' => 'redirect', 'url' => $hold->redirect_url, 'payment_id' => $hold->public_id] : null;
    }
}
