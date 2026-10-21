<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Driver position during an active trip — broadcast immediately (not queued) to keep latency low. */
class DriverLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $tripPublicId,
        public int $driverId,
        public float $lat,
        public float $lng,
        public ?float $heading,
        public ?float $speed,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('trip.'.$this->tripPublicId)];
    }

    public function broadcastAs(): string
    {
        return 'driver.location';
    }

    public function broadcastWith(): array
    {
        return [
            'driver_id' => $this->driverId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'heading' => $this->heading,
            'speed' => $this->speed,
            'at' => now()->toIso8601String(),
        ];
    }
}
