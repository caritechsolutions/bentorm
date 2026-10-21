<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TripAdminController extends Controller
{
    /** GET /admin/trips?status=&from=&to=&driver_id=&rider_id= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Trip::query()->with(['rider', 'driver', 'vehicle']);
        foreach (['status', 'driver_id', 'rider_id', 'vehicle_type'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }
        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to);
        }

        return TripResource::collection($q->latest('id')->paginate(25));
    }

    /** GET /admin/trips/{trip} — includes breadcrumbs and offers. */
    public function show(Trip $trip): JsonResponse
    {
        $trip->load(['rider', 'driver.driverProfile', 'vehicle', 'ratings', 'payments', 'offers.driver']);

        return response()->json([
            'trip' => (new TripResource($trip))->resolve(),
            'offers' => $trip->offers->map(fn ($o) => [
                'driver_id' => $o->driver_id, 'driver_name' => $o->driver?->name, 'distance_m' => $o->distance_m,
                'response' => $o->response, 'offered_at' => $o->offered_at?->toIso8601String(), 'responded_at' => $o->responded_at?->toIso8601String(),
            ]),
            'route' => $trip->locations()->get(['lat', 'lng', 'speed_mps', 'heading', 'recorded_at']),
            'payments' => $trip->payments,
        ]);
    }

    /** POST /admin/trips/{trip}/cancel */
    public function cancel(Request $request, Trip $trip, TripService $trips): TripResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new TripResource($trips->cancel($trip, $request->user(), $data['reason'])->load(['rider', 'driver']));
    }
}
