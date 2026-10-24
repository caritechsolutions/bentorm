<?php

namespace App\Services;

use App\Enums\OfferResponse;
use App\Enums\TripStatus;
use App\Events\OfferWithdrawn;
use App\Events\TripOffered;
use App\Events\TripUpdated;
use App\Jobs\DispatchTripJob;
use App\Contracts\DistanceProvider;
use App\Models\DispatchSetting;
use App\Models\Trip;
use App\Models\TripOffer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Offers a requested trip to nearby idle drivers in waves (or all at once); first to accept wins.
 * How is set per service area in the admin panel (DispatchSetting).
 */
class DispatchService
{
    public function __construct(private readonly DriverLocationService $locations) {}

    /** Kick off dispatch for a freshly requested trip. */
    public function start(Trip $trip): void
    {
        Cache::put($this->waveKey($trip), 0, now()->addHour());
        Cache::put($this->ringKey($trip), 0, now()->addHour());
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

        $settings = DispatchSetting::forTrip($trip);
        $alreadyOffered = $trip->offers()->pluck('driver_id')->all();

        // Each wave (round of offers) searches a ring: start radius, widening by the step up to the maximum.
        // A ring with nobody new in it widens straight away without using up a wave. At the widest ring, an
        // empty search does use a wave: it waits one offer period for drivers to come online or free up.
        while (true) {
            $wave = (int) Cache::get($this->waveKey($trip), 0);
            if ($wave >= $settings->max_waves) {
                $this->markNoDrivers($trip);

                return;
            }
            $ring = (int) Cache::get($this->ringKey($trip), 0);
            $radius = $settings->radiusForWave($ring);
            $candidates = $this->candidates($trip, $settings, $radius, $alreadyOffered);
            $widest = $settings->radiusForWave($ring + 1) <= $radius;
            Cache::put($this->ringKey($trip), $widest ? $ring : $ring + 1, now()->addHour());

            if ($candidates) {
                Cache::put($this->waveKey($trip), $wave + 1, now()->addHour());
                break;
            }
            if ($widest) {
                Cache::put($this->waveKey($trip), $wave + 1, now()->addHour());
                $this->scheduleNextWave($trip, $settings);

                return;
            }
        }

        $ttl = $settings->offer_ttl_seconds;
        foreach ($candidates as $c) {
            $offer = TripOffer::query()->create([
                'trip_id' => $trip->id,
                'driver_id' => $c['driver_id'],
                'distance_m' => $c['distance_m'],
                'eta_s' => $c['eta_s'] ?? null,
                'radius_m' => $radius,
                'wave' => $wave + 1,
                'offered_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
            ]);
            event(new TripOffered($offer));
        }

        $this->scheduleNextWave($trip, $settings);
    }

    /**
     * Who gets this wave's offers: idle drivers of the right type within $radius, whose vehicle fits,
     * not offered this trip before, best first by the area's ranking, cut to the batch (or broadcast limit).
     *
     * @param  array<int, int>  $exclude
     * @return array<int, array{driver_id: int, distance_m: int, eta_s?: ?int}>
     */
    private function candidates(Trip $trip, DispatchSetting $s, int $radius, array $exclude): array
    {
        $limit = $s->isBroadcast() ? max(1, $s->broadcast_limit) : max(1, $s->batch_size);
        $fetch = $s->isBroadcast() ? $limit * 2 : max($limit * 4, $s->ranking === 'eta' ? $s->eta_candidates : 0, $s->ranking === 'longest_idle' ? 50 : 0);

        // Deliveries go to any vehicle, but only drivers who opted in to deliveries.
        $nearby = $trip->isDelivery()
            ? $this->deliveryDrivers($this->locations->nearbyIdle($trip->pickup_lat, $trip->pickup_lng, $radius, null, $fetch * 3, $exclude))
            : $this->locations->nearbyIdle($trip->pickup_lat, $trip->pickup_lng, $radius, $trip->vehicle_type, $fetch, $exclude);
        $fit = $this->fitting($trip, $nearby, PHP_INT_MAX);

        $ranked = match ($s->ranking) {
            'eta' => $this->byArrival($trip, $fit, max(1, $s->eta_candidates)),
            'longest_idle' => $this->byLongestIdle($fit),
            default => $fit, // already nearest first
        };

        return array_slice($ranked, 0, $limit);
    }

    /** Keep the drivers who said yes to deliveries. */
    private function deliveryDrivers(array $nearby): array
    {
        if (! $nearby) {
            return [];
        }
        $yes = \App\Models\DriverProfile::query()->whereIn('user_id', array_column($nearby, 'driver_id'))
            ->where('accepts_deliveries', true)->pluck('user_id')->all();

        return array_values(array_filter($nearby, fn ($c) => in_array($c['driver_id'], $yes)));
    }

    /** Fastest by road first. Only the nearest $checkCount are routed (cost); the rest follow, nearest first. */
    private function byArrival(Trip $trip, array $fit, int $checkCount): array
    {
        $check = array_values(array_filter(array_slice($fit, 0, $checkCount), fn ($c) => isset($c['lat'], $c['lng'])));
        $rest = array_values(array_filter($fit, fn ($c) => ! in_array($c['driver_id'], array_column($check, 'driver_id'), true)));
        if (! $check) {
            return $fit;
        }
        $etas = app(DistanceProvider::class)->etaMany(array_map(fn ($c) => [$c['lat'], $c['lng']], $check), (float) $trip->pickup_lat, (float) $trip->pickup_lng);
        foreach ($check as $i => &$c) {
            $c['eta_s'] = $etas[$i] ?? null;
        }
        unset($c);
        usort($check, fn ($a, $b) => [$a['eta_s'] === null, $a['eta_s'] ?? 0, $a['distance_m']] <=> [$b['eta_s'] === null, $b['eta_s'] ?? 0, $b['distance_m']]);

        return array_merge($check, $rest);
    }

    /** Waiting longest first (unknown counts as just started); nearest breaks ties. */
    private function byLongestIdle(array $fit): array
    {
        $now = now()->timestamp;
        usort($fit, fn ($a, $b) => [$a['idle_since'] ?? $now, $a['distance_m']] <=> [$b['idle_since'] ?? $now, $b['distance_m']]);

        return $fit;
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
            Cache::forget($this->ringKey($locked));

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
        Cache::forget($this->ringKey($trip));
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

    private function scheduleNextWave(Trip $trip, ?DispatchSetting $settings = null): void
    {
        $ttl = ($settings ?? DispatchSetting::forTrip($trip))->offer_ttl_seconds;
        DispatchTripJob::dispatch($trip->id)->delay(now()->addSeconds($ttl));
    }

    private function waveKey(Trip $trip): string
    {
        return "trip:{$trip->id}:dispatch_wave";
    }

    private function ringKey(Trip $trip): string
    {
        return "trip:{$trip->id}:dispatch_ring";
    }
}
