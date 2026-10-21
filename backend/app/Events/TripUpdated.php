<?php

namespace App\Events;

use App\Http\Resources\TripResource;
use App\Models\Trip;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Any status change on a trip — sent to rider, driver (if any) and the trip channel. */
class TripUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Trip $trip) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('trip.'.$this->trip->public_id),
            new PrivateChannel('user.'.$this->trip->rider_id),
        ];
        if ($this->trip->driver_id) {
            $channels[] = new PrivateChannel('user.'.$this->trip->driver_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'trip.updated';
    }

    public function broadcastWith(): array
    {
        return ['trip' => (new TripResource($this->trip->loadMissing(['rider', 'driver.driverProfile', 'vehicle'])))->resolve()];
    }
}
