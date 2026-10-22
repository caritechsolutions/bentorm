<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key, 'name' => $this->name, 'description' => $this->description,
            'max_passengers' => $this->max_passengers, 'icon_url' => $this->icon_url,
            'sort_order' => $this->sort_order, 'is_active' => $this->is_active,
        ];
    }
}
