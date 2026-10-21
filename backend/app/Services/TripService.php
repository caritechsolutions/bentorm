<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Events\DriverLocationUpdated;
use App\Events\TripUpdated;
use App\Models\FareConfig;
use App\Models\Payment;
use App\Models\Rating;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly DispatchService $dispatch,
        private readonly DriverLocationService $locations,
    ) {}

    // ---------------------------------------------------------------- rider

    public function request(User $rider, array $data): Trip
    {
        if ($rider->activeTrip()) {
            throw ValidationException::withMessages(['trip' => ['You already have an active trip.']]);
        }

        $quote = $this->pricing->estimate(
            $data['pickup_lat'], $data['pickup_lng'], $data['dropoff_lat'], $data['dropoff_lng'], $data['vehicle_type'],
        );

        $trip = Trip::query()->create([
            'rider_id' => $rider->id,
            'vehicle_type' => $data['vehicle_type'],
            'status' => TripStatus::Requested,
            'pickup_lat' => $data['pickup_lat'],
            'pickup_lng' => $data['pickup_lng'],
            'pickup_address' => $data['pickup_address'] ?? null,
            'dropoff_lat' => $data['dropoff_lat'],
            'dropoff_lng' => $data['dropoff_lng'],
            'dropoff_address' => $data['dropoff_address'] ?? null,
            'estimated_distance_m' => $quote['distance_m'],
            'estimated_duration_s' => $quote['duration_s'],
            'estimated_fare' => $quote['fare'],
            'currency' => $quote['currency'],
            'fare_breakdown' => $quote['breakdown'],
            'surge_multiplier' => $quote['surge_multiplier'],
            'payment_method' => $data['payment_method'] ?? PaymentMethod::Cash->value,
            'passengers' => $data['passengers'] ?? 1,
            'rider_note' => $data['rider_note'] ?? null,
            'requested_at' => now(),
        ]);

        $this->dispatch->start($trip);
        event(new TripUpdated($trip));

        return $trip;
    }

    public function cancel(Trip $trip, User $by, ?string $reason = null): Trip
    {
        return DB::transaction(function () use ($trip, $by, $reason) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if (! $t->status->canTransitionTo(TripStatus::Cancelled)) {
                abort(409, 'This trip can no longer be cancelled.');
            }
            if ($t->status === TripStatus::InProgress && ! $by->isAdmin()) {
                abort(409, 'A trip in progress cannot be cancelled from the app. Contact support.');
            }

            $who = $by->isAdmin() ? 'admin' : ($t->driver_id === $by->id ? 'driver' : 'rider');
            $fee = 0.0;
            // Rider pays a cancellation fee once a driver has accepted (and it was not the driver's fault).
            if ($who === 'rider' && in_array($t->status, [TripStatus::Accepted, TripStatus::Arrived], true)) {
                $fee = (float) (FareConfig::forType($t->vehicle_type)?->cancellation_fee ?? 0);
            }

            if ($t->status === TripStatus::Requested) {
                $this->dispatch->withdrawAll($t, 'cancelled');
            }

            $t->forceFill([
                'status' => TripStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $who,
                'cancellation_reason' => $reason,
                'cancellation_fee' => $fee,
                'payment_status' => $fee > 0 ? PaymentStatus::Pending : PaymentStatus::Waived,
            ])->save();

            if ($t->driver_id) {
                $this->releaseDriver($t->driver);
            }

            event(new TripUpdated($t));

            return $t;
        });
    }

    public function rate(Trip $trip, User $rater, int $score, ?string $comment): Rating
    {
        if ($trip->status !== TripStatus::Completed) {
            abort(409, 'Only completed trips can be rated.');
        }
        $rateeId = $trip->rider_id === $rater->id ? $trip->driver_id : $trip->rider_id;
        if (! $rateeId) {
            abort(409, 'Nobody to rate on this trip.');
        }

        $rating = Rating::query()->updateOrCreate(
            ['trip_id' => $trip->id, 'rater_id' => $rater->id],
            ['ratee_id' => $rateeId, 'score' => $score, 'comment' => $comment],
        );

        // Keep the driver's aggregate rating current.
        $ratee = User::query()->find($rateeId);
        if ($ratee?->isDriver() && $ratee->driverProfile) {
            $agg = Rating::query()->where('ratee_id', $rateeId)->selectRaw('AVG(score) as avg, COUNT(*) as cnt')->first();
            $ratee->driverProfile->forceFill([
                'rating_avg' => round((float) $agg->avg, 2),
                'rating_count' => (int) $agg->cnt,
            ])->save();
        }

        return $rating;
    }

    // ---------------------------------------------------------------- driver

    public function arrive(Trip $trip, User $driver): Trip
    {
        return $this->transition($trip, $driver, TripStatus::Arrived, ['arrived_at' => now()]);
    }

    public function start(Trip $trip, User $driver): Trip
    {
        return $this->transition($trip, $driver, TripStatus::InProgress, ['started_at' => now()]);
    }

    public function complete(Trip $trip, User $driver, ?float $endLat = null, ?float $endLng = null): Trip
    {
        return DB::transaction(function () use ($trip, $driver, $endLat, $endLng) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $this->assertDriverCan($t, $driver, TripStatus::Completed);

            if ($endLat !== null && $endLng !== null) {
                $this->recordLocation($t, $endLat, $endLng, null, null);
            }

            $distanceM = $this->trackedDistance($t) ?: ($t->estimated_distance_m ?? 0);
            $durationS = (int) max(0, ($t->started_at ?? now())->diffInSeconds(now(), true));
            $quote = $this->pricing->finalFare($t->vehicle_type, $distanceM, $durationS, (float) $t->surge_multiplier);

            $t->forceFill([
                'status' => TripStatus::Completed,
                'completed_at' => now(),
                'actual_distance_m' => $distanceM,
                'actual_duration_s' => $durationS,
                'final_fare' => $quote['fare'],
                'fare_breakdown' => $quote['breakdown'],
                // Cash is settled in the car; card is captured later by the gateway flow.
                'payment_status' => $t->payment_method === PaymentMethod::Cash ? PaymentStatus::Paid : PaymentStatus::Pending,
            ])->save();

            Payment::query()->create([
                'trip_id' => $t->id,
                'user_id' => $t->rider_id,
                'method' => $t->payment_method->value,
                'gateway' => $t->payment_method === PaymentMethod::Cash ? null : 'pending',
                'amount' => $quote['fare'],
                'currency' => $t->currency,
                'status' => $t->payment_method === PaymentMethod::Cash ? 'captured' : 'pending',
            ]);

            $driver->driverProfile?->increment('completed_trips');
            $this->releaseDriver($driver);

            event(new TripUpdated($t));

            return $t;
        });
    }

    /** Driver position ping — updates presence and, if on a trip, records and broadcasts it. */
    public function driverPing(User $driver, float $lat, float $lng, ?float $heading, ?float $speed): ?Trip
    {
        $this->locations->update($driver, $lat, $lng, $heading, $speed);

        $trip = $driver->activeTrip();
        if ($trip && in_array($trip->status, [TripStatus::Accepted, TripStatus::Arrived, TripStatus::InProgress], true)) {
            if ($trip->status === TripStatus::InProgress) {
                $this->recordLocation($trip, $lat, $lng, $heading, $speed);
            }
            event(new DriverLocationUpdated($trip->public_id, $driver->id, $lat, $lng, $heading, $speed));
        }

        return $trip;
    }

    public function goOnline(User $driver): void
    {
        $profile = $driver->driverProfile;
        if (! $profile || $profile->status !== DriverStatus::Approved) {
            abort(403, 'Your driver account is not approved yet.');
        }
        $vehicle = $profile->currentVehicle;
        if (! $vehicle || ! $vehicle->isApproved()) {
            abort(403, 'Select an approved vehicle before going online.');
        }

        $profile->forceFill(['is_online' => true])->save();
        $this->locations->goOnline($driver, $vehicle->vehicle_type);
    }

    public function goOffline(User $driver): void
    {
        if ($driver->activeTrip()) {
            abort(409, 'Finish your current trip before going offline.');
        }
        $driver->driverProfile?->forceFill(['is_online' => false])->save();
        $this->locations->goOffline($driver);
    }

    // ---------------------------------------------------------------- internals

    private function transition(Trip $trip, User $driver, TripStatus $to, array $extra): Trip
    {
        return DB::transaction(function () use ($trip, $driver, $to, $extra) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $this->assertDriverCan($t, $driver, $to);
            $t->forceFill(['status' => $to] + $extra)->save();
            event(new TripUpdated($t));

            return $t;
        });
    }

    private function assertDriverCan(Trip $t, User $driver, TripStatus $to): void
    {
        if ($t->driver_id !== $driver->id) {
            abort(403, 'This is not your trip.');
        }
        if (! $t->status->canTransitionTo($to)) {
            abort(409, "Cannot move trip from {$t->status->value} to {$to->value}.");
        }
    }

    private function recordLocation(Trip $trip, float $lat, float $lng, ?float $heading, ?float $speed): void
    {
        TripLocation::query()->create([
            'trip_id' => $trip->id, 'lat' => $lat, 'lng' => $lng,
            'heading' => $heading, 'speed_mps' => $speed, 'recorded_at' => now(),
        ]);
    }

    /** Sum of breadcrumb segments, ignoring GPS jitter under 5 m. */
    private function trackedDistance(Trip $trip): int
    {
        $points = $trip->locations()->get(['lat', 'lng']);
        if ($points->count() < 2) {
            return 0;
        }
        $total = 0.0;
        $prev = null;
        foreach ($points as $p) {
            if ($prev) {
                $d = Geo::haversine($prev->lat, $prev->lng, $p->lat, $p->lng);
                if ($d >= 5) {
                    $total += $d;
                }
            }
            $prev = $p;
        }

        return (int) round($total);
    }

    private function releaseDriver(?User $driver): void
    {
        if ($driver && $driver->driverProfile?->is_online) {
            $this->locations->setState($driver, 'idle');
        }
    }
}
