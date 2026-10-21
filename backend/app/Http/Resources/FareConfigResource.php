<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FareConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'vehicle_type' => $this->vehicle_type,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'currency' => $this->currency,
            'base_fare' => (float) $this->base_fare,
            'per_km' => (float) $this->per_km,
            'per_minute' => (float) $this->per_minute,
            'minimum_fare' => (float) $this->minimum_fare,
            'booking_fee' => (float) $this->booking_fee,
            'cancellation_fee' => (float) $this->cancellation_fee,
            'surge_multiplier' => (float) $this->surge_multiplier,
            'max_passengers' => $this->max_passengers,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
