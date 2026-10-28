<?php

namespace App\Http\Resources;

use App\Enums\DocumentStatus;
use Illuminate\Http\Request;


class VehicleResource extends Resource
{
    public function toArray(Request $request): array
    {
        $private = $request->user()?->isAdmin() || $request->user()?->id === $this->driver_id;
        // Fleet cars, to the drivers given them: which fleet, and whether someone else is driving it now.
        $fleetView = $this->fleet_id && $request->user() && ($request->user()->isAdmin() || $request->user()->isDriver());
        if ($fleetView) {
            $private = true;
        }

        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'colour' => $this->colour,
            'plate' => $this->plate,
            'seats' => $this->seats,
            'luggage_capacity' => $this->luggage_capacity,
            'features' => $this->features ?? [],
            'vehicle_type' => $this->vehicle_type,
            'status' => $this->when($private, $this->status),
            'status_note' => $this->when($private, $this->status_note),
            'photo_url' => $this->photoUrl(),
            'fleet' => $this->when($fleetView, fn () => ['id' => $this->fleet_id, 'name' => $this->fleet?->name]),
            'in_use_by' => $this->when($fleetView, fn () => $this->inUseBy($request->user()->id)?->firstName()),
        ];
    }

    /** Approved rider-visible photo (front photo by default) so riders can recognise the car. */
    private function photoUrl(): ?string
    {
        $doc = $this->documents()->where('status', DocumentStatus::Approved->value)
            ->whereHas('type', fn ($q) => $q->where('visible_to_riders', true))
            ->orderBy('id', 'desc')->first();

        return $doc ? route('files.document', ['document' => $doc->id]) : null;
    }
}
