<?php

namespace App\Console\Commands;

use App\Enums\TripStatus;
use App\Events\TripUpdated;
use App\Models\Trip;
use App\Services\DispatchService;
use App\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Safety net: a trip stuck in "requested" far longer than dispatch should take
 * (e.g. the queue was down) is marked no_drivers so the rider isn't left hanging.
 */
class ExpireStaleTrips extends Command
{
    protected $signature = 'trips:expire-stale {--minutes=10}';

    protected $description = 'Mark trips stuck in requested state as no_drivers';

    public function handle(DispatchService $dispatch, PaymentService $payments): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $stale = Trip::query()->where('status', TripStatus::Requested->value)->where('requested_at', '<', $cutoff)->get();

        foreach ($stale as $trip) {
            $dispatch->withdrawAll($trip, 'no_drivers');
            $trip->forceFill([
                'status' => TripStatus::NoDrivers, 'cancelled_at' => now(), 'cancelled_by' => 'system',
                'cancellation_reason' => 'Request timed out', 'payment_status' => 'waived',
            ])->save();
            $payments->releaseHold($trip);
            event(new TripUpdated($trip));
        }

        // Card holds never confirmed (abandoned 3-D Secure) — give up after 15 minutes.
        $abandoned = Trip::query()->where('status', TripStatus::PendingPayment->value)->where('created_at', '<', now()->subMinutes(15))->get();
        foreach ($abandoned as $trip) {
            $trip->forceFill([
                'status' => TripStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => 'system',
                'cancellation_reason' => 'Card authorisation not completed', 'payment_status' => 'waived',
            ])->save();
            $payments->releaseHold($trip);
            event(new TripUpdated($trip));
        }

        $this->info("Expired {$stale->count()} stale request(s) and {$abandoned->count()} unconfirmed card hold(s).");

        return self::SUCCESS;
    }
}
