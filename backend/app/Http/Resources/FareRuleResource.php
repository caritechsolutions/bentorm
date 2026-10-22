<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FareRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_area_id' => $this->service_area_id,
            'vehicle_type' => $this->vehicle_type,
            'name' => $this->name,
            'kind' => $this->kind,
            'multiplier' => $this->multiplier,
            'amount' => $this->amount,
            'days' => $this->days,
            'start_time' => $this->start_time ? substr($this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr($this->end_time, 0, 5) : null,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'pickup_zone_id' => $this->pickup_zone_id,
            'dropoff_zone_id' => $this->dropoff_zone_id,
            'zone_applies_to' => $this->zone_applies_to,
            'bidirectional' => $this->bidirectional,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
        ];
    }
}
