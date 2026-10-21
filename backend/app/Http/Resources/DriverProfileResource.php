<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isSelfOrAdmin = $request->user() && ($request->user()->id === $this->user_id || $request->user()->isAdmin());

        return [
            'status' => $this->status,
            'status_note' => $this->when($isSelfOrAdmin, $this->status_note),
            'is_online' => $this->is_online,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'completed_trips' => $this->completed_trips,
            'licence_number' => $this->when($isSelfOrAdmin, $this->licence_number),
            'licence_expiry' => $this->when($isSelfOrAdmin, $this->licence_expiry?->toDateString()),
            'psv_permit_number' => $this->when($isSelfOrAdmin, $this->psv_permit_number),
            'current_vehicle_id' => $this->current_vehicle_id,
            'current_vehicle' => new VehicleResource($this->whenLoaded('currentVehicle')),
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
