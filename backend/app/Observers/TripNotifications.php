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
            title: $trip->isDelivery() ? 'New delivery' : 'New ride request',
            body: trim(($trip->isDelivery() ? 'Collect at: ' : 'Pickup: ').($trip->pickup_address ?: 'see the app').($earn ? " · you earn about {$cur} ".number_format($earn, 2) : '')),
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
        if ($t->isDelivery()) {
            $this->deliveryChanged($t);

            return;
        }
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
            TripStatus::Requested, TripStatus::PendingPayment => $t->scheduled_at && $t->getOriginal('status') === TripStatus::Scheduled
                ? $this->push->notify($t->rider_id, new PushMessage('Finding your driver', 'Your ride booked for '.$this->localTime($t).' is starting — we\'re finding a driver now.', $data, collapseKey: $key))
                : null,
            TripStatus::Cancelled => $this->cancelled($t, $data, $key),
            default => null,
        };
    }

    /**
     * Deliveries: the customer hears about the order (OrderService sends "on the way" / "delivered"); here only
     * "a driver is collecting it". A driver whose job was called off is told. Nobody found → a new job is sent.
     */
    private function deliveryChanged(Trip $t): void
    {
        $data = ['type' => 'trip', 'trip_id' => $t->public_id, 'status' => $t->status->value, 'order_id' => $t->order?->public_id];
        if ($t->status === TripStatus::Accepted) {
            $this->push->notify($t->rider_id, new PushMessage(($t->driver?->firstName() ?? 'A driver').' will collect your order',
                trim(($t->vehicle ? "{$t->vehicle->make} {$t->vehicle->model} · {$t->vehicle->plate}. " : '').'We\'ll tell you when it\'s on the way.'),
                ['type' => 'order', 'order_id' => $t->order?->public_id, 'status' => 'driver_assigned'], collapseKey: 'order-'.$t->order?->public_id));
        } elseif ($t->status === TripStatus::Cancelled && $t->driver_id && $t->cancelled_by !== 'driver') {
            $this->push->notify($t->driver_id, new PushMessage('Delivery cancelled', 'This delivery was called off. You don\'t need to go to the store.',
                $data, collapseKey: 'trip-'.$t->public_id, urgent: true));
        }
    }

    private function localTime(Trip $t): string
    {
        return $t->scheduled_at->copy()->setTimezone($t->serviceArea?->timezone ?? config('app.timezone'))->format('g:i a');
    }

    private function cancelled(Trip $t, array $data, string $key): void
    {
        if ($t->scheduled_at && $t->getOriginal('status') === TripStatus::Scheduled && $t->cancelled_by === 'system') {
            $this->push->notify($t->rider_id, new PushMessage('Booked ride cancelled', "Your ride booked for {$this->localTime($t)} was cancelled: ".lcfirst($t->cancellation_reason ?? 'please book again').'.', $data, collapseKey: $key));

            return;
        }
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
