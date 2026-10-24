<?php

namespace App\Observers;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripOffer;
use App\Services\PushService;
use App\Support\PushMessage;

/**
 * Push notifications for ride events, so the apps don't have to be open:
 *  driver — a new ride offer (rings), the rider cancelled, the rider asked to change stops;
 *  rider  — driver on the way, driver arrived, driver cancelled, answer to a stop change, no drivers found.
 * Watches saves, so every path (app, admin panel, scheduler) is covered.
 */
class TripNotifications
{
    public function __construct(private readonly PushService $push) {}

    public function offerCreated(TripOffer $offer): void
    {
        $trip = $offer->trip;
        $earn = $trip->estimatedDriverEarnings();
        $cur = $trip->serviceArea?->currency ?? '';
        $ttl = $offer->expires_at ? max(1, now()->diffInSeconds($offer->expires_at, false)) : 20;
        $this->push->notify($offer->driver_id, new PushMessage(
            title: 'New ride request',
            body: trim('Pickup: '.($trip->pickup_address ?: 'see the app').($earn ? " · you earn about {$cur} ".number_format($earn, 2) : '')),
            data: ['type' => 'offer', 'trip_id' => $trip->public_id, 'offer_id' => $offer->id],
            channel: 'ride_offers',
            ttlSeconds: (int) ceil($ttl),
            collapseKey: 'offer-'.$trip->public_id,
            urgent: true,
        ));
    }

    public function tripUpdated(Trip $trip): void
    {
        if ($trip->wasChanged('status')) {
            $this->statusChanged($trip);
        }
        if ($trip->wasChanged('stop_change')) {
            $this->stopChangeChanged($trip);
        }
    }

    private function statusChanged(Trip $t): void
    {
        $driverName = $t->driver?->firstName() ?? 'Your driver';
        $data = ['type' => 'trip', 'trip_id' => $t->public_id, 'status' => $t->status->value];
        $key = 'trip-'.$t->public_id;

        match ($t->status) {
            TripStatus::Accepted => $this->push->notify($t->rider_id, new PushMessage(
                "{$driverName} is on the way",
                trim(($t->vehicle ? "{$t->vehicle->make} {$t->vehicle->model} · {$t->vehicle->plate}. " : '').'Your ride PIN is in the app.'),
                $data, collapseKey: $key, urgent: true)),
            TripStatus::Arrived => $this->push->notify($t->rider_id, new PushMessage(
                "{$driverName} has arrived", 'Your driver is waiting at the pickup point.', $data, collapseKey: $key, urgent: true)),
            TripStatus::NoDrivers => $this->push->notify($t->rider_id, new PushMessage(
                'No drivers available', 'Sorry, no driver could take your ride. Please try again in a few minutes.', $data, collapseKey: $key)),
            TripStatus::Cancelled => $this->cancelled($t, $data, $key),
            default => null,
        };
    }

    private function cancelled(Trip $t, array $data, string $key): void
    {
        if ($t->cancelled_by === 'rider' && $t->driver_id) {
            $this->push->notify($t->driver_id, new PushMessage('Ride cancelled', ($t->rider?->firstName() ?? 'The rider').' cancelled the ride.', $data, collapseKey: $key, urgent: true));
        } elseif (in_array($t->cancelled_by, ['driver', 'admin', 'system'], true)) {
            $this->push->notify($t->rider_id, new PushMessage('Ride cancelled', $t->cancelled_by === 'driver'
                ? 'Your driver cancelled. You can request another ride in the app.'
                : 'Your ride was cancelled.', $data, collapseKey: $key));
        }
    }

    private function stopChangeChanged(Trip $t): void
    {
        $status = $t->stop_change['status'] ?? null;
        $data = ['type' => 'stops', 'trip_id' => $t->public_id, 'stop_change' => (string) $status];
        if ($status === 'pending' && $t->driver_id) {
            $this->push->notify($t->driver_id, new PushMessage('Stop change requested',
                ($t->rider?->firstName() ?? 'The rider').' wants to change the stops. Open the app to accept or decline.', $data, collapseKey: 'stops-'.$t->public_id, urgent: true));
        } elseif (in_array($status, ['accepted', 'declined'], true)) {
            $this->push->notify($t->rider_id, new PushMessage($status === 'accepted' ? 'Stops updated' : 'Stops not changed',
                $status === 'accepted' ? 'Your driver accepted the new stops.' : 'Your driver couldn\'t change the stops. The ride continues as before.',
                $data, collapseKey: 'stops-'.$t->public_id));
        }
    }
}
