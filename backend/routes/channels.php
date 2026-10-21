<?php

use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Per-user channel: offers, trip updates addressed to this user.
Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id || $user->isAdmin());

// Per-trip channel: driver location + status; rider, driver and admins may listen.
Broadcast::channel('trip.{publicId}', function (User $user, string $publicId) {
    $trip = Trip::query()->where('public_id', $publicId)->first();

    return $trip && ($trip->involves($user) || $user->isAdmin());
});
