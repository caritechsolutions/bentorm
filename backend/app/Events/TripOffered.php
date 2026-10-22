<?php

namespace App\Events;

use App\Http\Resources\TripResource;
use App\Models\TripOffer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Sent to a driver when a trip is offered to them. */
class TripOffered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TripOffer $offer) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->offer->driver_id)];
    }

    public function broadcastAs(): string
    {
        return 'trip.offered';
    }

    public function broadcastWith(): array
    {
        return [
            'offer' => [
                'id' => $this->offer->id,
                'distance_m' => $this->offer->distance_m,
                'expires_at' => $this->offer->expires_at->toIso8601String(),
                'estimated_earnings' => $this->offer->trip->estimatedDriverEarnings(),
            ],
            'trip' => (new TripResource($this->offer->trip->loadMissing('rider')))->resolve(),
        ];
    }
}
