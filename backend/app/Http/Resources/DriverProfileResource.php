<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;


class DriverProfileResource extends Resource
{
    public function toArray(Request $request): array
    {
        // No user (queued broadcasts) => public view, so licence details never reach riders over websockets.
        $private = $request->user() && ($request->user()->id === $this->user_id || $request->user()->isAdmin());

        return [
            'status' => $this->status,
            'status_note' => $this->when($private, $this->status_note),
            'service_area_id' => $this->service_area_id,
            'is_online' => $this->is_online,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'completed_trips' => $this->completed_trips,
            'licence_number' => $this->when($private, $this->licence_number),
            'licence_expiry' => $this->when($private, $this->licence_expiry?->toDateString()),
            'psv_permit_number' => $this->when($private, $this->psv_permit_number),
            'current_vehicle_id' => $this->current_vehicle_id,
            'current_vehicle' => new VehicleResource($this->whenLoaded('currentVehicle')),
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
