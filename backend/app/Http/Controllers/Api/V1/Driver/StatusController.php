<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\OfferResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\TripOffer;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function __construct(private readonly TripService $trips) {}

    /** POST /driver/online */
    public function online(Request $request): JsonResponse
    {
        $this->trips->goOnline($request->user());

        return response()->json(['is_online' => true]);
    }

    /** POST /driver/offline */
    public function offline(Request $request): JsonResponse
    {
        $this->trips->goOffline($request->user());

        return response()->json(['is_online' => false]);
    }

    /** POST /driver/location — heartbeat + position; returns the active trip (if any) so the app can resync. */
    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed' => ['nullable', 'numeric', 'min:0'],
        ]);

        $trip = $this->trips->driverPing($request->user(), $data['lat'], $data['lng'], $data['heading'] ?? null, $data['speed'] ?? null);

        return response()->json([
            'ok' => true,
            'active_trip' => $trip ? (new TripResource($trip->load(['rider', 'vehicle'])))->resolve($request) : null,
        ]);
    }

    /** GET /driver/offers — pending offers for this driver (for resync after reconnect). */
    public function offers(Request $request): JsonResponse
    {
        $offers = TripOffer::query()->where('driver_id', $request->user()->id)
            ->where('response', OfferResponse::Pending->value)->where('expires_at', '>', now())
            ->with('trip.rider')->get();

        return response()->json([
            'offers' => $offers->map(fn (TripOffer $o) => [
                'id' => $o->id,
                'distance_m' => $o->distance_m,
                'expires_at' => $o->expires_at->toIso8601String(),
                'trip' => (new TripResource($o->trip))->resolve($request),
            ])->values(),
        ]);
    }
}
