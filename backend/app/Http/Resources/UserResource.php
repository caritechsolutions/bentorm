<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isSelfOrAdmin = $request->user() && ($request->user()->id === $this->id || $request->user()->isAdmin());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->when($isSelfOrAdmin, $this->phone),
            'email' => $this->when($isSelfOrAdmin, $this->email),
            'role' => $this->role,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->when($isSelfOrAdmin, $this->is_active),
            'driver_profile' => new DriverProfileResource($this->whenLoaded('driverProfile')),
            'created_at' => $this->when($isSelfOrAdmin, $this->created_at?->toIso8601String()),
        ];
    }
}
