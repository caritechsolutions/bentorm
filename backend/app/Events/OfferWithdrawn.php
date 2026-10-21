<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Sent to a driver when an offer they hold is no longer available (taken, expired or cancelled). */
class OfferWithdrawn implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $driverId, public int $offerId, public string $tripPublicId, public string $reason) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->driverId)];
    }

    public function broadcastAs(): string
    {
        return 'offer.withdrawn';
    }

    public function broadcastWith(): array
    {
        return ['offer_id' => $this->offerId, 'trip_id' => $this->tripPublicId, 'reason' => $this->reason];
    }
}
