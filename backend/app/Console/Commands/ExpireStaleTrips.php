<?php

namespace App\Console\Commands;

use App\Enums\TripStatus;
use App\Events\TripUpdated;
use App\Models\Trip;
use App\Services\DispatchService;
use Illuminate\Console\Command;

/**
 * Safety net: a trip stuck in "requested" far longer than dispatch should take
 * (e.g. the queue was down) is marked no_drivers so the rider isn't left hanging.
 */
class ExpireStaleTrips extends Command
{
    protected $signature = 'trips:expire-stale {--minutes=10}';

    protected $description = 'Mark trips stuck in requested state as no_drivers';

    public function handle(DispatchService $dispatch): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $stale = Trip::query()->where('status', TripStatus::Requested->value)->where('requested_at', '<', $cutoff)->get();

        foreach ($stale as $trip) {
            $dispatch->withdrawAll($trip, 'no_drivers');
            $trip->forceFill([
                'status' => TripStatus::NoDrivers, 'cancelled_at' => now(), 'cancelled_by' => 'system',
                'cancellation_reason' => 'Request timed out',
            ])->save();
            event(new TripUpdated($trip));
        }

        $this->info("Expired {$stale->count()} stale trip(s).");

        return self::SUCCESS;
    }
}
