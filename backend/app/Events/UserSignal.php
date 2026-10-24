<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A small real-time note to one user's apps (chat message, call ring / offer / answer / ICE candidate, call ended).
 * Sent now (not queued): call set-up is time-critical. The apps also poll, so a missed one is not lost.
 */
class UserSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<string, mixed> $data */
    public function __construct(public int $userId, public string $type, public array $data) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return $this->type; // chat.message | call.ring | call.signal | call.updated
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}
