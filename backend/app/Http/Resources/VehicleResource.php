<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'status' => $this->status,
            'documents' => $this->when($request->user()?->isAdmin() || $request->user()?->id === $this->driver_id, $this->documents),
        ];
    }
}
