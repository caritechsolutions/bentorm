<?php

namespace App\Http\Resources;

use App\Enums\DocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $private = $request->user()?->isAdmin() || $request->user()?->id === $this->driver_id;

        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'colour' => $this->colour,
            'plate' => $this->plate,
            'seats' => $this->seats,
            'vehicle_type' => $this->vehicle_type,
            'status' => $this->when($private, $this->status),
            'status_note' => $this->when($private, $this->status_note),
            'photo_url' => $this->photoUrl(),
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
