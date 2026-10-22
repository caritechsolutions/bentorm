<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDriverOrAdmin = $request->user() && ($request->user()->isAdmin() || $request->user()->id === $this->driver_id);

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
            'timestamps' => [
                'requested_at' => $this->requested_at?->toIso8601String(),
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'arrived_at' => $this->arrived_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
            'ratings' => RatingResource::collection($this->whenLoaded('ratings')),
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
