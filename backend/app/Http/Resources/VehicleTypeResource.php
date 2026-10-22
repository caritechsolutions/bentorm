<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;


class VehicleTypeResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key, 'name' => $this->name, 'description' => $this->description,
            'max_passengers' => $this->max_passengers, 'max_luggage' => $this->max_luggage, 'icon_url' => $this->icon_url,
            'sort_order' => $this->sort_order, 'is_active' => $this->is_active,
        ];
    }
}
