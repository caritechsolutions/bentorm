<?php

namespace App\Services;

use App\Enums\TripStatus;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetCash;
use App\Models\FleetDriver;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Phone;
use App\Support\PushMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fleet rules: inviting drivers, which fleet cars a driver may use, one driver per car at a time, and taking a car
 * away (a driver mid-trip finishes the trip first, then is set offline with the reason).
 */
class FleetService
{
    public function __construct(private readonly DriverLocationService $locations, private readonly PushService $push) {}

    // ---------------------------------------------------------------- invitations

    /** @param int[] $vehicleIds */
    public function invite(Fleet $fleet, string $phone, ?string $name, array $vehicleIds, ?User $by): FleetDriver
    {
        try {
            $phone = Phone::normalise($phone);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid mobile number.']);
        }
        $user = User::query()->where('phone', $phone)->first();
        if ($user?->isAdmin()) {
            throw ValidationException::withMessages(['phone' => 'That number belongs to an admin account.']);
        }
        $row = FleetDriver::query()->firstOrNew(['fleet_id' => $fleet->id, 'phone' => $phone]);
        if ($row->exists && in_array($row->status, [FleetDriver::ACTIVE, FleetDriver::INVITED], true)) {
            throw ValidationException::withMessages(['phone' => $row->status === FleetDriver::ACTIVE ? 'This driver is already in your fleet.' : 'This driver has already been invited.']);
        }
        $row->fill(['driver_id' => $user?->id, 'name' => filled($name) ? trim($name) : $row->name, 'status' => FleetDriver::INVITED,
            'invited_by' => $by?->id, 'responded_at' => null, 'removed_at' => null])->save();
        $row->vehicles()->sync($fleet->vehicles()->whereKey($vehicleIds)->pluck('id'));

        if ($user) {
            $this->push->notify($user->id, new PushMessage("{$fleet->name} invited you", 'Open Ride Coop Driver to accept and drive their cars.', ['type' => 'fleet_invite']));
        }

        return $row;
    }

    /** Invitations waiting for this driver (matched by account or, before they signed up, by phone). */
    public function invitesFor(User $driver): Collection
    {
        return FleetDriver::query()->where('status', FleetDriver::INVITED)
            ->where(fn ($q) => $q->where('driver_id', $driver->id)->orWhere(fn ($w) => $w->whereNull('driver_id')->where('phone', $driver->phone)))
            ->whereHas('fleet', fn ($q) => $q->where('status', 'approved'))
            ->with(['fleet', 'vehicles'])->latest('id')->get();
    }

    public function respond(FleetDriver $invite, User $driver, bool $accept): FleetDriver
    {
        abort_unless($invite->status === FleetDriver::INVITED && ($invite->driver_id === $driver->id || ($invite->driver_id === null && $invite->phone === $driver->phone)), 404);
        $invite->forceFill(['driver_id' => $driver->id, 'status' => $accept ? FleetDriver::ACTIVE : FleetDriver::DECLINED, 'responded_at' => now()])->save();

        return $invite;
    }

    /** Change which cars a driver may use; taking away the car they're in sends them offline (after any trip). */
    public function setVehicles(FleetDriver $fd, array $vehicleIds): void
    {
        $fd->vehicles()->sync($fd->fleet->vehicles()->whereKey($vehicleIds)->pluck('id'));
        if ($fd->driver) {
            $this->enforce($fd->driver, "{$fd->fleet->name} took away access to this car.");
        }
    }

    /** Remove a driver from the fleet (or withdraw an invitation). */
    public function remove(FleetDriver $fd): void
    {
        $fd->forceFill(['status' => FleetDriver::REMOVED, 'removed_at' => now()])->save();
        $fd->vehicles()->detach();
        if ($fd->driver) {
            $this->enforce($fd->driver, "{$fd->fleet->name} removed you from their fleet.");
        }
    }

    // ---------------------------------------------------------------- access

    /** May this driver drive this car? Own cars always; fleet cars when given to them by an approved fleet. */
    public function canUse(User $driver, Vehicle $vehicle): bool
    {
        if (! $vehicle->isFleetCar()) {
            return $vehicle->driver_id === $driver->id;
        }

        return FleetDriver::query()->where('fleet_id', $vehicle->fleet_id)->where('driver_id', $driver->id)->where('status', FleetDriver::ACTIVE)
            ->whereHas('vehicles', fn ($q) => $q->whereKey($vehicle->id))
            ->whereHas('fleet', fn ($q) => $q->where('status', 'approved'))->exists();
    }

    /** The driver's own cars and the fleet cars they may use. */
    public function vehiclesFor(User $driver): Collection
    {
        $fleetCarIds = DB::table('fleet_driver_vehicle')
            ->join('fleet_drivers', 'fleet_drivers.id', '=', 'fleet_driver_vehicle.fleet_driver_id')
            ->join('fleets', 'fleets.id', '=', 'fleet_drivers.fleet_id')
            ->where('fleet_drivers.driver_id', $driver->id)->where('fleet_drivers.status', FleetDriver::ACTIVE)->where('fleets.status', 'approved')
            ->pluck('fleet_driver_vehicle.vehicle_id');

        return Vehicle::query()->where(fn ($q) => $q->where('driver_id', $driver->id)->orWhereIn('id', $fleetCarIds))
            ->with('fleet')->orderByRaw('fleet_id is not null')->latest('id')->get();
    }

    /** Someone else online in this fleet car? (One driver per car.) */
    public function assertFree(Vehicle $vehicle, User $driver): void
    {
        if ($vehicle->isFleetCar() && ($other = $vehicle->inUseBy($driver->id))) {
            abort(409, "{$vehicle->plate} is in use by {$other->firstName()}. They need to go offline or switch cars first.");
        }
    }

    /**
     * If the driver's selected car is no longer theirs to use: set them offline now with the reason, or — mid-trip —
     * let them finish (called again on every ping, so it happens as soon as the trip ends).
     */
    public function enforce(User $driver, ?string $reason = null): bool
    {
        $profile = $driver->driverProfile()->first(); // fresh: callers may hold a stale copy
        $vehicle = $profile?->currentVehicle;
        if (! $vehicle || $this->canUse($driver, $vehicle)) {
            if ($profile?->is_online && $profile->offline_reason) {
                $profile->forceFill(['offline_reason' => null])->save(); // access given back during the trip
            }

            return false;
        }
        if ($driver->activeTrip()) {
            if ($reason) {
                $profile->forceFill(['offline_reason' => $reason])->save(); // kept for when the trip ends
            }

            return false;
        }
        $reason ??= $profile->offline_reason ?: ($vehicle->fleet ? "You no longer have access to {$vehicle->plate} from {$vehicle->fleet->name}." : "You no longer have access to {$vehicle->plate}.");
        $wasOnline = $profile->is_online;
        $profile->forceFill(['is_online' => false, 'current_vehicle_id' => null, 'offline_reason' => $wasOnline ? $reason : null])->save();
        if ($wasOnline) {
            $this->locations->goOffline($driver);
            $this->push->notify($driver->id, new PushMessage('You\'re offline', $reason.' Choose another car to go online.', ['type' => 'offline']));
        }

        return true;
    }

    /**
     * Admin: move a driver's own car into a fleet (the owner bought it, or registered it themselves first). The
     * driver who had it can keep driving it as one of the fleet's drivers.
     */
    public function moveIntoFleet(Vehicle $car, Fleet $fleet, bool $keepDriver): void
    {
        DB::transaction(function () use ($car, $fleet, $keepDriver) {
            $driver = $car->driver;
            $car->forceFill(['fleet_id' => $fleet->id, 'driver_id' => null])->save();
            if ($driver && $keepDriver) {
                $fd = FleetDriver::query()->firstOrNew(['fleet_id' => $fleet->id, 'phone' => $driver->phone]);
                $fd->fill(['driver_id' => $driver->id, 'name' => $driver->name, 'status' => FleetDriver::ACTIVE, 'responded_at' => now(), 'removed_at' => null])->save();
                $fd->vehicles()->syncWithoutDetaching([$car->id]);
            }
        });
        if ($driver = User::query()->whereHas('driverProfile', fn ($q) => $q->where('current_vehicle_id', $car->id))->first()) {
            $this->enforce($driver, "{$car->plate} now belongs to {$fleet->name}.");
        }
    }

    /** The fleet was suspended or rejected: everyone in its cars goes offline (after any trip). */
    public function offTheRoad(Fleet $fleet, string $reason): void
    {
        foreach ($this->onTheRoad($fleet) as $driver) {
            $this->enforce($driver, $reason);
        }
    }

    // ---------------------------------------------------------------- money

    /** Cash collected by a fleet's driver in a fleet car: the driver holds it for the owner. */
    public function bookCash(Trip $t, float $collected): void
    {
        if (! $t->fleet_id || ! $t->driver_id || round($collected, 2) <= 0 || FleetCash::query()->where('trip_id', $t->id)->exists()) {
            return;
        }
        FleetCash::query()->create(['fleet_id' => $t->fleet_id, 'driver_id' => $t->driver_id, 'trip_id' => $t->id, 'amount' => round($collected, 2),
            'currency' => $t->currency ?: 'BBD', 'description' => sprintf('Cash ride %s%s', $t->requested_at?->format('j M') ?? '', $t->vehicle ? " in {$t->vehicle->plate}" : '')]);
    }

    /** The owner received cash from a driver. */
    public function recordCashReceived(Fleet $fleet, int $driverId, float $amount, ?string $note, ?User $by): FleetCash
    {
        return FleetCash::query()->create(['fleet_id' => $fleet->id, 'driver_id' => $driverId, 'amount' => -round($amount, 2), 'currency' => 'BBD',
            'description' => 'Handed to the owner'.($note ? " · {$note}" : ''), 'created_by' => $by?->id]);
    }

    /** Drivers online in this fleet's cars right now: [vehicle_id => User]. */
    public function onTheRoad(Fleet $fleet): array
    {
        return DriverProfile::query()->where('is_online', true)->whereIn('current_vehicle_id', $fleet->vehicles()->pluck('id'))
            ->with('user')->get()->mapWithKeys(fn ($p) => [$p->current_vehicle_id => $p->user])->all();
    }

    /**
     * Every car of the fleet for the portal's live map: who is driving it and where (online cars only have a position).
     *
     * @return array<int, array<string, mixed>>
     */
    public function live(Fleet $fleet): array
    {
        $drivers = $this->onTheRoad($fleet);
        $out = [];
        foreach ($fleet->vehicles()->orderBy('plate')->get() as $v) {
            $d = $drivers[$v->id] ?? null;
            $pos = $d ? $this->locations->position($d) : null;
            $out[] = ['id' => $v->id, 'plate' => $v->plate, 'car' => trim("{$v->make} {$v->model}"), 'driver' => $d?->name, 'phone' => $d?->phone,
                'state' => $d ? ($d->activeTrip() ? 'busy' : 'idle') : 'offline',
                'lat' => $pos['lat'] ?? null, 'lng' => $pos['lng'] ?? null, 'heading' => $pos['heading'] ?? null];
        }

        return $out;
    }

    /** Today's finished trips and what the fleet earned. @return array{trips: int, earnings: float} */
    public function today(Fleet $fleet): array
    {
        $q = Trip::query()->where('fleet_id', $fleet->id)->where('status', TripStatus::Completed->value)->where('completed_at', '>=', now()->startOfDay());

        return ['trips' => (clone $q)->count(), 'earnings' => round((float) (clone $q)->sum('driver_earnings'), 2)];
    }
}
