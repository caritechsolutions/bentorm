<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;


class ServiceAreaResource extends Resource
{
    public function toArray(Request $request): array
    {
        $admin = $request->user()?->isAdmin() ?? false;

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'type' => $this->type,
            'code' => $this->code,
            'name' => $this->name,
            'country_code' => $this->country_code,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'tax_rate' => $this->tax_rate,
            'tax_label' => $this->tax_label,
            'commission_rate' => $this->when($admin, $this->commission_rate),
            'rounding_increment' => $this->rounding_increment,
            'hold_buffer_rate' => $this->hold_buffer_rate,
            'message' => $this->message,
            'priority' => $this->when($admin, $this->priority),
            'is_active' => $this->is_active,
            'bbox' => ['min_lat' => $this->min_lat, 'max_lat' => $this->max_lat, 'min_lng' => $this->min_lng, 'max_lng' => $this->max_lng],
            'polygon' => $this->when($request->boolean('with_polygon', true), $this->polygon),
            'children' => ServiceAreaResource::collection($this->whenLoaded('children')),
        ];
    }
}
