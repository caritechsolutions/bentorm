<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FareConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_area_id' => $this->service_area_id,
            'vehicle_type' => $this->vehicle_type,
            'currency' => $this->serviceArea?->currency,
            'base_fare' => $this->base_fare,
            'per_km' => $this->per_km,
            'per_minute' => $this->per_minute,
            'minimum_fare' => $this->minimum_fare,
            'booking_fee' => $this->booking_fee,
            'cancellation_fee' => $this->cancellation_fee,
            'free_wait_minutes' => $this->free_wait_minutes,
            'wait_per_minute' => $this->wait_per_minute,
            'is_active' => $this->is_active,
            'amounts_exclude_tax' => true,
        ];
    }
}
