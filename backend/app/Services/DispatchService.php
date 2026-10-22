<?php

namespace App\Services;

use App\Enums\OfferResponse;
use App\Enums\TripStatus;
use App\Events\OfferWithdrawn;
use App\Events\TripOffered;
use App\Events\TripUpdated;
use App\Jobs\DispatchTripJob;
use App\Models\Trip;
use App\Models\TripOffer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Offers a requested trip to nearby idle drivers in waves; first to accept wins.
 */
class DispatchService
{
    public function __construct(private readonly DriverLocationService $locations) {}

    /** Kick off dispatch for a freshly requested trip. */
    public function start(Trip $trip): void
    {
        Cache::put($this->waveKey($trip), 0, now()->addHour());
        DispatchTripJob::dispatch($trip->id);
    }

    /**
     * Run one wave: expire stale offers, offer to the next batch, and schedule the next wave.
     * Called by DispatchTripJob.
     */
    public function runWave(Trip $trip): void
    {
        $trip->refresh();
        if ($trip->status !== TripStatus::Requested) {
            return; // accepted or cancelled in the meantime
        }

        // Expire any pending offers from the previous wave.
        $expired = $trip->offers()->where('response', OfferResponse::Pending->value)->where('expires_at', '<=', now())->get();
        foreach ($expired as $offer) {
            $offer->update(['response' => OfferResponse::Expired, 'responded_at' => now()]);
            event(new OfferWithdrawn($offer->driver_id, $offer->id, $trip->public_id, 'expired'));
        }

        // If there are still live pending offers, wait for them.
        if ($trip->offers()->where('response', OfferResponse::Pending->value)->where('expires_at', '>', now())->exists()) {
            $this->scheduleNextWave($trip);

            return;
        }

        $wave = (int) Cache::get($this->waveKey($trip), 0);
        if ($wave >= (int) config('dispatch.max_waves')) {
            $this->markNoDrivers($trip);

            return;
        }

        $alreadyOffered = $trip->offers()->pluck('driver_id')->all();
        $batch = (int) config('dispatch.offer_batch_size');
        // Over-fetch, then keep only drivers whose current vehicle fits the party, luggage and requested features.
        $candidates = $this->fitting($trip, $this->locations->nearbyIdle(
            $trip->pickup_lat, $trip->pickup_lng,
            (int) config('dispatch.search_radius_m'),
            $trip->vehicle_type,
            $batch * 4,
            $alreadyOffered,
        ), $batch);

        if (empty($candidates)) {
            // Nobody new in range this wave — count it and try again.
            Cache::put($this->waveKey($trip), $wave + 1, now()->addHour());
            $this->scheduleNextWave($trip);

            return;
        }

        $ttl = (int) config('dispatch.offer_ttl_seconds');
        foreach ($candidates as $c) {
            $offer = TripOffer::query()->create([
                'trip_id' => $trip->id,
                'driver_id' => $c['driver_id'],
                'distance_m' => $c['distance_m'],
                'offered_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
            ]);
            event(new TripOffered($offer));
        }

        Cache::put($this->waveKey($trip), $wave + 1, now()->addHour());
        $this->scheduleNextWave($trip);
    }

    /** Driver accepts an offer. Atomic — only one driver can win. */
    public function accept(Trip $trip, User $driver): Trip
    {
        return DB::transaction(function () use ($trip, $driver) {
            /** @var Trip $locked */
            $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TripStatus::Requested) {
                abort(409, 'This trip is no longer available.');
            }

            $offer = TripOffer::query()
                ->where('trip_id', $locked->id)
                ->where('driver_id', $driver->id)
                ->where('response', OfferResponse::Pending->value)
                ->first();

            if (! $offer || $offer->expires_at->isPast()) {
                abort(409, 'This offer has expired.');
            }

            if ($driver->activeTrip()) {
                abort(409, 'You already have an active trip.');
            }

            $profile = $driver->driverProfile;
            $locked->forceFill([
                'driver_id' => $driver->id,
                'vehicle_id' => $profile?->current_vehicle_id,
                'status' => TripStatus::Accepted,
                'accepted_at' => now(),
            ])->save();

            $offer->update(['response' => OfferResponse::Accepted, 'responded_at' => now()]);

            // Withdraw everyone else's offers.
            $others = TripOffer::query()->where('trip_id', $locked->id)->where('id', '!=', $offer->id)
                ->where('response', OfferResponse::Pending->value)->get();
            foreach ($others as $o) {
                $o->update(['response' => OfferResponse::Withdrawn, 'responded_at' => now()]);
                event(new OfferWithdrawn($o->driver_id, $o->id, $locked->public_id, 'taken'));
            }

            $this->locations->setState($driver, 'busy');
            Cache::forget($this->waveKey($locked));

            event(new TripUpdated($locked));

            return $locked;
        });
    }

    public function decline(Trip $trip, User $driver): void
    {
        $offer = TripOffer::query()->where('trip_id', $trip->id)->where('driver_id', $driver->id)
            ->where('response', OfferResponse::Pending->value)->first();
        if (! $offer) {
            return;
        }
        $offer->update(['response' => OfferResponse::Declined, 'responded_at' => now()]);

        // If nobody is left considering it, move to the next wave right away.
        $stillPending = $trip->offers()->where('response', OfferResponse::Pending->value)->where('expires_at', '>', now())->exists();
        if (! $stillPending) {
            DispatchTripJob::dispatch($trip->id);
        }
    }

    /** Withdraw all pending offers (trip cancelled before acceptance). */
    public function withdrawAll(Trip $trip, string $reason = 'cancelled'): void
    {
        $pending = $trip->offers()->where('response', OfferResponse::Pending->value)->get();
        foreach ($pending as $o) {
            $o->update(['response' => OfferResponse::Withdrawn, 'responded_at' => now()]);
            event(new OfferWithdrawn($o->driver_id, $o->id, $trip->public_id, $reason));
        }
        Cache::forget($this->waveKey($trip));
    }

    private function markNoDrivers(Trip $trip): void
    {
        $this->withdrawAll($trip, 'no_drivers');
        $trip->forceFill(['status' => TripStatus::NoDrivers, 'cancelled_at' => now(), 'cancelled_by' => 'system',
            'cancellation_reason' => 'No drivers available', 'payment_status' => \App\Enums\PaymentStatus::Waived])->save();
        app(PaymentService::class)->releaseHold($trip);
        event(new TripUpdated($trip));
    }

    /**
     * @param array<int, array{driver_id: int, distance_m: int}> $candidates nearest first
     * @return array<int, array{driver_id: int, distance_m: int}>
     */
    private function fitting(Trip $trip, array $candidates, int $limit): array
    {
        if (! $candidates) {
            return [];
        }
        $vehicles = Vehicle::query()
            ->join('driver_profiles', 'driver_profiles.current_vehicle_id', '=', 'vehicles.id')
            ->whereIn('driver_profiles.user_id', array_column($candidates, 'driver_id'))
            ->get(['vehicles.*', 'driver_profiles.user_id as profile_user_id'])
            ->keyBy('profile_user_id');

        $features = $trip->required_features ?? [];
        $out = [];
        foreach ($candidates as $c) {
            $v = $vehicles->get($c['driver_id']);
            if ($v && $v->fits((int) $trip->passengers, (int) $trip->luggage, $features)) {
                $out[] = $c;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    private function scheduleNextWave(Trip $trip): void
    {
        DispatchTripJob::dispatch($trip->id)->delay(now()->addSeconds((int) config('dispatch.offer_ttl_seconds')));
    }

    private function waveKey(Trip $trip): string
    {
        return "trip:{$trip->id}:dispatch_wave";
    }
}
