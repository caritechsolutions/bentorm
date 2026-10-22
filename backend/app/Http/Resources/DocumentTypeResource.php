<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'key' => $this->key, 'name' => $this->name, 'description' => $this->description,
            'applies_to' => $this->applies_to, 'service_area_id' => $this->service_area_id,
            'is_required' => $this->is_required, 'requires_expiry' => $this->requires_expiry,
            'requires_number' => $this->requires_number, 'is_image_only' => $this->is_image_only,
            'visible_to_riders' => $this->visible_to_riders, 'sort_order' => $this->sort_order, 'is_active' => $this->is_active,
        ];
    }
}
