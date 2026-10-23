<?php

namespace App\Events;

use App\Http\Resources\TripResource;
use App\Support\PublicView;
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

    /**
     * Resolved as nobody: everyone on these channels (rider AND driver) receives the same payload, so
     * per-viewer fields — the ride PIN, driver earnings, platform fee, phone numbers — must never be in it.
     * Apps fetch those over the API.
     */
    public function broadcastWith(): array
    {
        return ['trip' => PublicView::resolve(new TripResource($this->trip->loadMissing(['rider', 'driver.driverProfile', 'vehicle'])))];
    }
}
