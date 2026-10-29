<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Events\DriverLocationUpdated;
use App\Events\TripUpdated;
use App\Jobs\SendTripReceiptJob;
use App\Models\Payment;
use App\Models\Rating;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\User;
use App\Models\Vehicle;
use App\Plugins\PluginManager;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly DispatchService $dispatch,
        private readonly DriverLocationService $locations,
        private readonly ComplianceService $compliance,
        private readonly PluginManager $plugins,
        private readonly PaymentService $payments,
    ) {}

    // ---------------------------------------------------------------- rider

    /**
     * Cash: the trip is created as `requested` and dispatch starts immediately.
     * Card: the trip is created as `pending_payment`, the estimate + buffer is held on the saved card, and dispatch
     * starts once the hold is authorised (immediately, or after 3-D Secure). A declined hold cancels the trip.
     */
    public function request(User $rider, array $data): Trip
    {
        $scheduledAt = isset($data['scheduled_at']) ? self::scheduledTime($rider, $data['scheduled_at']) : null;
        if (! $scheduledAt && $rider->activeTrip()) {
            throw ValidationException::withMessages(['trip' => ['You already have an active trip.']]);
        }
        if ($owed = $rider->outstandingTrips()->first()) {
            throw ValidationException::withMessages(['payment' => [
                "Please settle {$owed->currency} ".number_format($owed->amount_due, 2).' owed on a previous trip first.',
            ]]);
        }

        $passengers = (int) ($data['passengers'] ?? 1);
        $luggage = (int) ($data['luggage'] ?? 0);
        $features = array_values(array_unique($data['features'] ?? []));
        $stops = self::normaliseStops($data['stops'] ?? []);

        // Geofences, stops and capacity are enforced inside the estimate.
        $quote = $this->pricing->estimate(
            (float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng'],
            $data['vehicle_type'], $scheduledAt, $passengers, $luggage, $stops,
        );

        $promo = filled($data['promo_code'] ?? null)
            ? app(PromoService::class)->check($data['promo_code'], $rider, 'rides', (float) $quote['fare'], $quote['service_area_id']) : null;

        $method = PaymentMethod::from($data['payment_method'] ?? PaymentMethod::Cash->value);
        $card = $method === PaymentMethod::Card
            ? $this->payments->cardForRide($rider, $data['payment_method_id'] ?? null, $quote['service_area_id'])
            : null;

        $trip = Trip::query()->create([
            'rider_id' => $rider->id,
            'service_area_id' => $quote['service_area_id'],
            'pickup_zone_id' => $quote['pickup_zone_id'],
            'dropoff_zone_id' => $quote['dropoff_zone_id'],
            'vehicle_type' => $data['vehicle_type'],
            'status' => $scheduledAt ? TripStatus::Scheduled : ($card ? TripStatus::PendingPayment : TripStatus::Requested),
            'scheduled_at' => $scheduledAt,
            'pickup_lat' => $data['pickup_lat'],
            'pickup_lng' => $data['pickup_lng'],
            'pickup_address' => $data['pickup_address'] ?? null,
            'dropoff_lat' => $data['dropoff_lat'],
            'dropoff_lng' => $data['dropoff_lng'],
            'dropoff_address' => $data['dropoff_address'] ?? null,
            'stops' => $stops ?: null,
            'pin' => self::newPin(),
            'estimated_distance_m' => $quote['distance_m'],
            'estimated_duration_s' => $quote['duration_s'],
            'estimated_fare' => $quote['fare'],
            'currency' => $quote['currency'],
            'fare_breakdown' => $quote['breakdown'],
            'surge_multiplier' => $quote['surge_multiplier'],
            'payment_method' => $method,
            'payment_method_id' => $card?->id,
            'hold_amount' => $card ? $quote['hold_amount'] : null,
            'passengers' => $passengers,
            'luggage' => $luggage,
            'required_features' => $features ?: null,
            'rider_note' => $data['rider_note'] ?? null,
            'requested_at' => $scheduledAt ? null : now(),
            'promo_code_id' => $promo?->id,
            'use_credit' => (bool) ($data['use_credit'] ?? false),
        ]);
        if ($promo) {
            app(PromoService::class)->reserve($promo, $rider, $trip);
        }

        if ($scheduledAt) {
            event(new TripUpdated($trip));

            return $trip; // the card is held and drivers are asked shortly before pickup (startScheduled)
        }

        if ($card) {
            // Authorised → requested + dispatch; 3-D Secure → stays pending_payment; declined → cancelled.
            $this->payments->placeHold($trip, $card);

            return $trip->fresh();
        }

        $this->dispatch->start($trip);
        event(new TripUpdated($trip));

        return $trip;
    }

    /** Validates a requested pickup time against App features (earliest / furthest ahead, 3 upcoming at most). */
    public static function scheduledTime(User $rider, string $value): \Carbon\CarbonImmutable
    {
        \App\Support\Features::require('scheduled_rides', 'Booking rides for later');
        try {
            $at = \Carbon\CarbonImmutable::parse($value)->utc()->startOfMinute();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['scheduled_at' => ['Choose a pickup date and time.']]);
        }
        $min = (int) \App\Support\Features::number('scheduled.min_minutes');
        $maxDays = (int) \App\Support\Features::number('scheduled.max_days');
        if ($at->lt(now()->addMinutes($min)->subMinute())) {
            throw ValidationException::withMessages(['scheduled_at' => ["Book at least {$min} minutes ahead, or request a ride now."]]);
        }
        if ($at->gt(now()->addDays($maxDays))) {
            throw ValidationException::withMessages(['scheduled_at' => ["Rides can be booked up to {$maxDays} days ahead."]]);
        }
        if (Trip::query()->where('rider_id', $rider->id)->where('status', TripStatus::Scheduled->value)->count() >= 3) {
            throw ValidationException::withMessages(['scheduled_at' => ['You already have 3 rides booked. Cancel one to book another.']]);
        }

        return $at;
    }

    /**
     * Scheduler: it's nearly pickup time — hold the card (or go straight to drivers for cash) and start dispatch.
     * A booking missed by more than 30 minutes (server was down) is cancelled instead.
     */
    public function startScheduled(Trip $trip): ?Trip
    {
        $t = DB::transaction(function () use ($trip) {
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->first();
            if (! $t || $t->status !== TripStatus::Scheduled) {
                return null;
            }
            if ($t->scheduled_at->lt(now()->subMinutes(30))) {
                $t->forceFill(['status' => TripStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => 'system',
                    'cancellation_reason' => 'Booking missed', 'payment_status' => PaymentStatus::Waived])->save();

                return $t;
            }
            $card = null;
            if ($t->payment_method === PaymentMethod::Card) {
                try {
                    $card = $this->payments->cardForRide($t->rider, $t->card?->public_id, $t->service_area_id);
                } catch (ValidationException) {
                    try {
                        $card = $this->payments->cardForRide($t->rider, null, $t->service_area_id); // the chosen card went: use the default
                    } catch (ValidationException) {
                        $card = null;
                    }
                }
                if (! $card) {
                    $t->forceFill(['status' => TripStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => 'system',
                        'cancellation_reason' => 'No card to pay with', 'payment_status' => PaymentStatus::Waived])->save();

                    return $t;
                }
            }
            $t->forceFill(['status' => $card ? TripStatus::PendingPayment : TripStatus::Requested, 'requested_at' => now(),
                'payment_method_id' => $card?->id ?? $t->payment_method_id])->save();

            return $t;
        });
        if (! $t) {
            return null;
        }
        if ($t->status === TripStatus::PendingPayment) {
            $this->payments->placeHold($t, $t->card);
        } elseif ($t->status === TripStatus::Requested) {
            $this->dispatch->start($t);
        }
        $t = $t->fresh();
        event(new TripUpdated($t));

        return $t;
    }

    /** Scheduler, every minute: start the bookings whose pickup is within the dispatch lead time. */
    public function startDueScheduled(): int
    {
        $lead = (int) \App\Support\Features::number('scheduled.dispatch_lead_minutes');
        $n = 0;
        Trip::query()->where('status', TripStatus::Scheduled->value)->where('scheduled_at', '<=', now()->addMinutes($lead))
            ->orderBy('scheduled_at')->get()->each(function (Trip $t) use (&$n) {
                if ($this->startScheduled($t)) {
                    $n++;
                }
            });

        return $n;
    }

    public function cancel(Trip $trip, User $by, ?string $reason = null): Trip
    {
        $t = DB::transaction(function () use ($trip, $by, $reason) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if (! $t->status->canTransitionTo(TripStatus::Cancelled)) {
                abort(409, 'This trip can no longer be cancelled.');
            }
            if ($t->status === TripStatus::InProgress && ! $by->isAdmin()) {
                abort(409, 'A trip in progress cannot be cancelled from the app. Contact support.');
            }

            // The person's part in this trip decides the label (an admin booking their own ride is its rider).
            $who = $t->driver_id === $by->id ? 'driver' : ($t->rider_id === $by->id ? 'rider' : ($by->isAdmin() ? 'admin' : 'rider'));

            // Rider pays a cancellation fee once a driver has accepted (never when the driver or admin cancels).
            $charge = ['total' => 0.0, 'tax' => 0.0, 'subtotal' => 0.0, 'platform_fee' => 0.0, 'driver_earnings' => 0.0];
            if ($who === 'rider' && in_array($t->status, [TripStatus::Accepted, TripStatus::Arrived], true)) {
                $charge = $this->pricing->cancellationCharge($t);
            }

            if (in_array($t->status, [TripStatus::Requested, TripStatus::PendingPayment], true)) {
                $this->dispatch->withdrawAll($t, 'cancelled');
            }

            $t->forceFill([
                'status' => TripStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $who,
                'cancellation_reason' => $reason,
                'cancellation_fee' => $charge['total'],
                'subtotal' => $charge['total'] > 0 ? $charge['subtotal'] : null,
                'tax_amount' => $charge['total'] > 0 ? $charge['tax'] : null,
                'platform_fee' => $charge['total'] > 0 ? $charge['platform_fee'] : null,
                'driver_earnings' => $charge['total'] > 0 ? $charge['driver_earnings'] : null,
                'amount_due' => $charge['total'],
                'payment_status' => $charge['total'] > 0 ? PaymentStatus::Pending : PaymentStatus::Waived,
            ])->save();

            if ($t->driver_id) {
                $this->releaseDriver($t->driver);
            }

            return $t;
        });

        // Money after commit: take the fee from the hold (or the card / record it owed), otherwise release the hold.
        if ($t->cancellation_fee > 0) {
            $this->payments->chargeCancellationFee($t);
            $this->payments->releaseHold($t->fresh()); // void anything left (no-op once captured)
        } else {
            $this->payments->releaseHold($t);
        }

        $t = $t->fresh();
        event(new TripUpdated($t));

        return $t;
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

        $ratee = User::query()->find($rateeId);
        // As a driver (ratings from riders) or as a rider (ratings from drivers): a person can be both.
        $asDriver = $rateeId === $trip->driver_id;
        $agg = Rating::query()->where('ratee_id', $rateeId)
            ->whereHas('trip', fn ($q) => $q->where($asDriver ? 'driver_id' : 'rider_id', $rateeId))
            ->selectRaw('AVG(score) as avg, COUNT(*) as cnt')->first();
        if ($asDriver && $ratee?->driverProfile) {
            $ratee->driverProfile->forceFill(['rating_avg' => round((float) $agg->avg, 2), 'rating_count' => (int) $agg->cnt])->save();
        } elseif (! $asDriver && $ratee) {
            $ratee->forceFill(['rider_rating_avg' => round((float) $agg->avg, 2), 'rider_rating_count' => (int) $agg->cnt])->save();
        }

        return $rating;
    }

    // ---------------------------------------------------------------- driver

    public function arrive(Trip $trip, User $driver): Trip
    {
        $this->assertNearRider($trip, $driver, 'mark that you\'ve arrived');

        return $this->transition($trip, $driver, TripStatus::Arrived, ['arrived_at' => now()]);
    }

    /** Start the trip. The driver must enter the rider's PIN (rides created before PINs existed have none). */
    public function start(Trip $trip, User $driver, ?string $pin = null): Trip
    {
        if ($trip->isDelivery()) {
            abort(409, 'The store confirms the hand-over in their portal — the job moves on by itself.');
        }
        $this->assertNearRider($trip, $driver, 'start the ride');

        return $this->withPin($trip, $driver, $pin, TripStatus::InProgress, 'the rider', function (Trip $t) {
            $t->forceFill([
                'status' => TripStatus::InProgress, 'started_at' => now(),
                'pin_verified_at' => $t->pin ? now() : null, 'pin_attempts' => 0,
            ])->save();
        });
    }

    /**
     * Checks the PIN for a move to $to, then runs $apply on the locked trip. Wrong PINs are counted (after the
     * transaction, so the count isn't rolled back); too many lock it for a few minutes.
     */
    private function withPin(Trip $trip, User $driver, ?string $pin, TripStatus $to, string $person, \Closure $apply): Trip
    {
        $max = (int) config('bentorm.pin_max_attempts', 5);
        $lockMinutes = (int) config('bentorm.pin_lock_minutes', 2);

        $result = DB::transaction(function () use ($trip, $driver, $pin, $max, $lockMinutes, $to, $apply) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $this->assertDriverCan($t, $driver, $to);

            if ($t->pin) {
                if ($t->pin_attempts >= $max && $t->pin_failed_at?->gt(now()->subMinutes($lockMinutes))) {
                    return 'locked';
                }
                if (! is_string($pin) || ! hash_equals($t->pin, trim($pin))) {
                    return $pin === null || trim($pin) === '' ? 'missing' : 'wrong';
                }
            }
            $apply($t);
            event(new TripUpdated($t));

            return $t;
        });

        if ($result instanceof Trip) {
            return $result;
        }
        $name = $person === 'the rider' ? ($trip->rider?->firstName() ?? 'the rider') : $person;
        if ($result === 'locked') {
            abort(429, "Too many wrong PINs. Ask {$name} to read the PIN from their app, and try again in {$lockMinutes} minutes.");
        }
        if ($result === 'missing') {
            throw ValidationException::withMessages(['pin' => ["Ask {$person} for their 4-digit PIN."]]);
        }

        // Wrong PIN: count it (a lock that has expired starts a fresh count).
        $t = $trip->fresh();
        $attempts = ($t->pin_attempts >= $max) ? 1 : $t->pin_attempts + 1;
        $t->forceFill(['pin_attempts' => $attempts, 'pin_failed_at' => now()])->save();
        $left = max(0, $max - $attempts);
        throw ValidationException::withMessages(['pin' => [$left > 0
            ? "That PIN is not right. {$left} ".($left === 1 ? 'try' : 'tries').' left.'
            : "That PIN is not right. Wait {$lockMinutes} minutes, then check it with {$person}."]]);
    }

    // ================================================================= deliveries

    /** The store handed the order to the driver (store portal). The driver can tap "arrived" or not. */
    public function deliveryPickedUp(Trip $trip): Trip
    {
        return DB::transaction(function () use ($trip) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if (! $t->isDelivery() || ! in_array($t->status, [TripStatus::Accepted, TripStatus::Arrived], true)) {
                throw ValidationException::withMessages(['status' => ['The driver has to accept the job before collecting the order.']]);
            }
            $t->forceFill(['status' => TripStatus::InProgress, 'arrived_at' => $t->arrived_at ?? now(), 'started_at' => now()])->save();
            event(new TripUpdated($t));

            return $t;
        });
    }

    /** At the customer's door: the customer's PIN completes the delivery, then the order is captured. */
    private function completeDelivery(Trip $trip, User $driver, ?string $pin, ?float $endLat, ?float $endLng): Trip
    {
        $t = $this->withPin($trip, $driver, $pin, TripStatus::Completed, 'the customer', function (Trip $t) use ($driver, $endLat, $endLng) {
            if ($endLat !== null && $endLng !== null) {
                $this->recordLocation($t, $endLat, $endLng, null, null);
            }
            $order = $t->order;
            $fee = (float) $order->delivery_fee;
            $ex = (float) $order->delivery_fee_ex_tax;
            $t->forceFill([
                'status' => TripStatus::Completed,
                'completed_at' => now(),
                'pin_verified_at' => now(), 'pin_attempts' => 0,
                'actual_distance_m' => $this->trackedDistance($t) ?: ($t->estimated_distance_m ?? 0),
                'actual_duration_s' => (int) max(0, ($t->started_at ?? now())->diffInSeconds(now(), true)),
                'wait_duration_s' => ($t->arrived_at && $t->started_at) ? (int) max(0, $t->arrived_at->diffInSeconds($t->started_at, true)) : 0,
                'subtotal' => $ex, 'tax_amount' => round($fee - $ex, 2), 'final_fare' => $fee,
                'platform_fee' => (float) $order->delivery_platform_share, 'driver_earnings' => (float) $order->driver_earnings,
                'tip_amount' => (float) $order->tip, // checkout tip: 100% to the driver
                // The customer paid by card with the order; nothing is owed on the trip itself.
                'payment_status' => PaymentStatus::Paid, 'amount_due' => 0,
            ])->save();
            $driver->driverProfile?->increment('completed_trips');
            $this->releaseDriver($driver);
        });

        app(OrderService::class)->onDelivered($t->order);

        return $t->fresh();
    }

    // ================================================================= stops

    /**
     * Rider changes the stops. Before a driver accepts it applies at once; afterwards the driver must
     * confirm. Stops already reached are kept; $stops are the remaining ones, in order.
     */
    public function changeStops(Trip $trip, User $rider, array $stops): Trip
    {
        return DB::transaction(function () use ($trip, $rider, $stops) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($t->rider_id !== $rider->id) {
                abort(403, 'This is not your trip.');
            }
            if (! in_array($t->status, [TripStatus::PendingPayment, TripStatus::Requested, TripStatus::Accepted, TripStatus::Arrived, TripStatus::InProgress], true)) {
                throw ValidationException::withMessages(['stops' => ['Stops can only be changed during a ride.']]);
            }
            $reached = array_values(array_filter($t->stops ?? [], fn ($s) => ! empty($s['reached_at'])));
            $all = array_merge($reached, self::normaliseStops($stops));
            $quote = $this->pricing->requote($t, $all);
            $change = [
                'stops' => $all,
                'estimated_fare' => $quote['fare'],
                'distance_m' => $quote['distance_m'],
                'duration_s' => $quote['duration_s'],
                'fare_breakdown' => $quote['breakdown'],
                'requested_at' => now()->toIso8601String(),
            ];

            if (in_array($t->status, [TripStatus::PendingPayment, TripStatus::Requested], true)) {
                $this->applyStops($t, $change);
                $t->forceFill(['stop_change' => null])->save();
            } else {
                $t->forceFill(['stop_change' => $change + ['status' => 'pending']])->save();
            }
            event(new TripUpdated($t));

            return $t;
        });
    }

    /** The rider withdraws a change the driver hasn't answered yet. */
    public function withdrawStopChange(Trip $trip, User $rider): Trip
    {
        if ($trip->rider_id !== $rider->id) {
            abort(403, 'This is not your trip.');
        }
        if (($trip->stop_change['status'] ?? null) === 'pending') {
            $trip->forceFill(['stop_change' => null])->save();
            event(new TripUpdated($trip));
        }

        return $trip;
    }

    /** Driver accepts or declines the rider's pending stop change. */
    public function respondToStopChange(Trip $trip, User $driver, bool $accept): Trip
    {
        return DB::transaction(function () use ($trip, $driver, $accept) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($t->driver_id !== $driver->id) {
                abort(403, 'This is not your trip.');
            }
            $change = $t->stop_change;
            if (($change['status'] ?? null) !== 'pending' || ! $t->isActive()) {
                abort(409, 'There is no stop change waiting for you.');
            }
            if ($accept) {
                $this->applyStops($t, $change);
            }
            $t->forceFill(['stop_change' => [
                'stops' => $change['stops'], 'estimated_fare' => $change['estimated_fare'],
                'status' => $accept ? 'accepted' : 'declined', 'requested_at' => $change['requested_at'], 'responded_at' => now()->toIso8601String(),
            ]])->save();
            event(new TripUpdated($t));

            return $t;
        });
    }

    /** Driver reached stop $index (0-based). Stops are visited in order, during the trip. */
    public function reachStop(Trip $trip, User $driver, int $index): Trip
    {
        return DB::transaction(function () use ($trip, $driver, $index) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($t->driver_id !== $driver->id) {
                abort(403, 'This is not your trip.');
            }
            if ($t->status !== TripStatus::InProgress) {
                abort(409, 'Stops are reached after the trip has started.');
            }
            $stops = $t->stops ?? [];
            if (! isset($stops[$index])) {
                abort(404, 'No such stop.');
            }
            foreach (array_slice($stops, 0, $index) as $earlier) {
                if (empty($earlier['reached_at'])) {
                    abort(409, 'Reach the earlier stops first.');
                }
            }
            if (empty($stops[$index]['reached_at'])) {
                $stops[$index]['reached_at'] = now()->toIso8601String();
                $t->forceFill(['stops' => $stops])->save();
                event(new TripUpdated($t));
            }

            return $t;
        });
    }

    private function applyStops(Trip $t, array $change): void
    {
        $t->forceFill([
            'stops' => $change['stops'] ?: null,
            'estimated_fare' => $change['estimated_fare'],
            'estimated_distance_m' => $change['distance_m'],
            'estimated_duration_s' => $change['duration_s'],
            'fare_breakdown' => $change['fare_breakdown'],
        ])->save();
    }

    /** [{lat, lng, address, reached_at}] from request input, keeping reached_at when present. */
    public static function normaliseStops(array $stops): array
    {
        return array_values(array_map(fn ($s) => [
            'lat' => round((float) $s['lat'], 7),
            'lng' => round((float) $s['lng'], 7),
            'address' => isset($s['address']) ? mb_substr((string) $s['address'], 0, 255) : null,
            'reached_at' => $s['reached_at'] ?? null,
        ], $stops));
    }

    public static function newPin(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function complete(Trip $trip, User $driver, ?float $endLat = null, ?float $endLng = null, ?string $pin = null): Trip
    {
        if ($trip->isDelivery()) {
            return $this->completeDelivery($trip, $driver, $pin, $endLat, $endLng);
        }
        $t = DB::transaction(function () use ($trip, $driver, $endLat, $endLng) {
            /** @var Trip $t */
            $t = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $this->assertDriverCan($t, $driver, TripStatus::Completed);

            if ($endLat !== null && $endLng !== null) {
                $this->recordLocation($t, $endLat, $endLng, null, null);
            }

            $distanceM = $this->trackedDistance($t) ?: ($t->estimated_distance_m ?? 0);
            $durationS = (int) max(0, ($t->started_at ?? now())->diffInSeconds(now(), true));
            $waitS = ($t->arrived_at && $t->started_at) ? (int) max(0, $t->arrived_at->diffInSeconds($t->started_at, true)) : 0;
            $quote = $this->pricing->finalFare($t, $distanceM, $durationS, $waitS);
            [$discount, $credit] = app(PromoService::class)->settleRide($t, (float) $quote['fare']);
            $payable = round(max(0, $quote['fare'] - $discount - $credit), 2);

            $isCash = $t->payment_method === PaymentMethod::Cash;

            $t->forceFill([
                'status' => TripStatus::Completed,
                'completed_at' => now(),
                'actual_distance_m' => $distanceM,
                'actual_duration_s' => $durationS,
                'wait_duration_s' => $waitS,
                'subtotal' => $quote['subtotal'],
                'tax_amount' => $quote['tax'],
                'final_fare' => $quote['fare'],
                'platform_fee' => $quote['platform_fee'],
                'driver_earnings' => $quote['driver_earnings'],
                'fare_breakdown' => $quote['breakdown'],
                'discount_amount' => $discount,
                'credit_amount' => $credit,
                // Cash is settled in the car; card is captured from the hold right after commit.
                'payment_status' => $isCash ? PaymentStatus::Paid : PaymentStatus::Pending,
                'amount_due' => $isCash ? 0 : $payable,
            ])->save();

            if ($isCash && $payable > 0) {
                Payment::query()->create([
                    'trip_id' => $t->id, 'user_id' => $t->rider_id, 'purpose' => 'trip_fare', 'method' => 'cash',
                    'amount' => $payable, 'currency' => $t->currency, 'status' => 'captured', 'paid_at' => now(),
                ]);
            }

            $driver->driverProfile?->increment('completed_trips');
            $this->releaseDriver($driver);

            return $t;
        });

        if ($t->payment_method === PaymentMethod::Card) {
            $t = $this->payments->settleTrip($t);
        }

        event(new TripUpdated($t));
        SendTripReceiptJob::dispatch($t->id);
        app(ReferralService::class)->onCompleted($t->rider);

        return $t;
    }

    /**
     * Driver position ping — updates presence and, if on a trip, records and broadcasts it.
     * Every ping re-states the driver's full presence (vehicle type, idle/busy) from the database, so a gap in
     * pings (no signal, phone asleep) can never leave an online driver invisible to dispatch.
     */
    public function driverPing(User $driver, float $lat, float $lng, ?float $heading, ?float $speed): ?Trip
    {
        $trip = $driver->activeTrip();
        $profile = $driver->driverProfile;
        if (! $trip && ! $profile?->is_online) {
            return null; // offline and not on a job: not dispatchable (the reply tells the app)
        }
        $car = $profile?->current_vehicle_id ? Vehicle::query()->whereKey($profile->current_vehicle_id)->first(['id', 'vehicle_type', 'fleet_id']) : null;
        // A fleet car taken away: once any trip is over, the driver is set offline (with the reason) instead.
        if ($car?->fleet_id && ! $trip && app(FleetService::class)->enforce($driver)) {
            return null;
        }
        $type = $car?->vehicle_type;
        $this->locations->update($driver, $lat, $lng, $heading, $speed, $type, $trip ? 'busy' : 'idle');

        if ($trip && in_array($trip->status, [TripStatus::Accepted, TripStatus::Arrived, TripStatus::InProgress], true)) {
            if ($trip->status === TripStatus::InProgress) {
                $this->recordLocation($trip, $lat, $lng, $heading, $speed);
            }
            try {
                event(new DriverLocationUpdated($trip->public_id, $driver->id, $lat, $lng, $heading, $speed));
            } catch (\Throwable $e) {
                report($e); // live updates down: the rider's app polls the position instead
            }
        }

        return $trip;
    }

    /**
     * Drivers still marked online whose app has gone quiet for $minutes (phone off, app closed): set them offline
     * so the admin panel and the app tell the truth. The app signs back on by itself if it's still running.
     */
    public function expireSilentDrivers(int $minutes = 10): int
    {
        $n = 0;
        $cutoff = now()->subMinutes($minutes)->timestamp;
        \App\Models\DriverProfile::query()->where('is_online', true)->with('user')->each(function ($p) use (&$n, $cutoff) {
            if (! $p->user || $this->locations->isOnline($p->user_id) || $p->user->activeTrip()) {
                return;
            }
            $seen = $this->locations->lastSeen($p->user_id);
            if ($seen !== null && $seen > $cutoff) {
                return;
            }
            $p->forceFill(['is_online' => false])->save();
            $this->locations->goOffline($p->user);
            app(PushService::class)->notify($p->user_id, new \App\Support\PushMessage('You\'re offline', 'We lost contact with your phone, so you were set offline. Open Ride Coop Driver to go online again.', ['type' => 'offline']));
            $n++;
        });

        return $n;
    }

    public function goOnline(User $driver): void
    {
        $issues = $this->compliance->canGoOnline($driver);
        if ($issues) {
            abort(response()->json(['message' => 'You cannot go online yet.', 'issues' => $issues], 403));
        }

        DB::transaction(function () use ($driver) {
            $profile = $driver->driverProfile;
            // One driver per fleet car: checked again under a lock so two drivers can't take it at the same moment.
            $vehicle = Vehicle::query()->whereKey($profile->current_vehicle_id)->lockForUpdate()->firstOrFail();
            app(FleetService::class)->assertFree($vehicle, $driver);
            $profile->forceFill(['is_online' => true, 'offline_reason' => null])->save();
            $this->locations->goOnline($driver, $vehicle->vehicle_type);
        });
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

    /**
     * Rides: the driver must be near the rider — where the rider's phone is (they may have walked from the pin) or
     * the pickup pin — before Arrived / Start (admin setting, 0 = off). Without a recent position for the driver
     * there's nothing to measure, so it's allowed.
     */
    private function assertNearRider(Trip $trip, User $driver, string $action): void
    {
        $max = \App\Support\Features::number('pickup.max_distance_m');
        if ($max <= 0 || $trip->isDelivery() || $trip->driver_id !== $driver->id) {
            return;
        }
        $me = $this->locations->position($driver);
        if (! $me || ($me['updated_at'] ?? 0) < now()->timestamp - RiderLocationService::FRESH_SECONDS) {
            return;
        }
        $toPin = Geo::haversine($me['lat'], $me['lng'], (float) $trip->pickup_lat, (float) $trip->pickup_lng);
        $rider = app(RiderLocationService::class)->get($trip);
        $toRider = $rider ? Geo::haversine($me['lat'], $me['lng'], $rider['lat'], $rider['lng']) : null;
        $nearest = $toRider === null ? $toPin : min($toPin, $toRider);
        if ($nearest <= $max) {
            return;
        }
        $name = $trip->rider?->firstName() ?? 'the rider';
        $far = $nearest >= 1000 ? number_format($nearest / 1000, 1).' km' : ((int) round($nearest, -1)).' m';
        abort(response()->json([
            'message' => "You're {$far} from {$name}. Get within ".(int) $max.' m of '.($toRider !== null ? "{$name} or " : '')."the pickup to {$action}.",
            'distance_m' => (int) round($nearest),
            'max_distance_m' => (int) $max,
        ], 409));
    }

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
