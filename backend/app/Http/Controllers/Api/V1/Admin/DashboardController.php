<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /** GET /admin/stats */
    public function stats(DriverLocationService $locations): JsonResponse
    {
        $today = now()->startOfDay();
        $active = array_map(fn ($s) => $s->value, TripStatus::active());

        return response()->json([
            'riders_total' => User::query()->where('role', 'rider')->count(),
            'drivers_total' => User::query()->where('role', 'driver')->count(),
            'drivers_pending' => DriverProfile::query()->where('status', DriverStatus::Pending->value)->count(),
            'drivers_online' => count($locations->allLive()),
            'trips_active' => Trip::query()->whereIn('status', $active)->count(),
            'trips_today' => Trip::query()->where('created_at', '>=', $today)->count(),
            'trips_completed_today' => Trip::query()->where('status', TripStatus::Completed->value)->where('completed_at', '>=', $today)->count(),
            'revenue_today' => (float) Trip::query()->where('status', TripStatus::Completed->value)->where('completed_at', '>=', $today)->sum('final_fare'),
            'trips_no_drivers_today' => Trip::query()->where('status', TripStatus::NoDrivers->value)->where('created_at', '>=', $today)->count(),
        ]);
    }

    /** GET /admin/live — online drivers with positions and active trips, for the ops map. */
    public function live(DriverLocationService $locations): JsonResponse
    {
        $drivers = $locations->allLive();
        $ids = array_column($drivers, 'driver_id');
        $users = User::query()->whereIn('id', $ids)->with('driverProfile.currentVehicle')->get()->keyBy('id');

        $out = [];
        foreach ($drivers as $d) {
            $u = $users->get($d['driver_id']);
            if (! $u) {
                continue;
            }
            $out[] = $d + [
                'name' => $u->name,
                'phone' => $u->phone,
                'vehicle' => $u->driverProfile?->currentVehicle ? [
                    'plate' => $u->driverProfile->currentVehicle->plate,
                    'make' => $u->driverProfile->currentVehicle->make,
                    'model' => $u->driverProfile->currentVehicle->model,
                ] : null,
            ];
        }

        $active = Trip::query()->whereIn('status', array_map(fn ($s) => $s->value, TripStatus::active()))
            ->with(['rider', 'driver'])->latest('id')->get();

        return response()->json([
            'drivers' => $out,
            'active_trips' => \App\Http\Resources\TripResource::collection($active)->resolve(),
        ]);
    }
}
