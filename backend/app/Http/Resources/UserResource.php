<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    private bool $self = false;

    /** Render as the account owner (used at login, before the request is authenticated). */
    public function asSelf(): static
    {
        $this->self = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $private = $this->self || ($request->user() && ($request->user()->id === $this->id || $request->user()->isAdmin()));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->when($private, $this->phone),
            'email' => $this->when($private, $this->email),
            'role' => $this->role,
            'country_code' => $this->when($private, $this->country_code),
            'avatar_url' => $this->avatar_path ? route('files.avatar', ['user' => $this->id]) : null,
            'is_active' => $this->when($private, $this->is_active),
            'driver_profile' => new DriverProfileResource($this->whenLoaded('driverProfile')),
            'created_at' => $this->when($private, $this->created_at?->toIso8601String()),
        ];
    }
}
