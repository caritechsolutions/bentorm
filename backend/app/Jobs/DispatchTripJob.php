<?php

namespace App\Jobs;

use App\Models\Trip;
use App\Services\DispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchTripJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $tripId) {}

    public function handle(DispatchService $dispatch): void
    {
        $trip = Trip::query()->find($this->tripId);
        if ($trip) {
            $dispatch->runWave($trip);
        }
    }
}
