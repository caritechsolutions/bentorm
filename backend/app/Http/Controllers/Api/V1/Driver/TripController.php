<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\DispatchService;
use App\Services\NavigationPolicy;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TripController extends Controller
{
    public function __construct(private readonly TripService $trips, private readonly DispatchService $dispatch) {}

    /** GET /driver/trips — driver history. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TripResource::collection(
            Trip::query()->where('driver_id', $request->user()->id)->with(['rider', 'vehicle'])->latest('id')->paginate(20)
        );
    }

    /** GET /driver/trips/active */
    public function active(Request $request): JsonResponse
    {
        $trip = $request->user()->activeTrip();

        return response()->json(['trip' => $trip ? (new TripResource($trip->load(['rider', 'vehicle'])))->resolve($request) : null]);
    }

    /** GET /driver/trips/{trip} — a trip this driver drove (or was assigned), e.g. after the rider cancelled. */
    public function show(Request $request, Trip $trip): TripResource
    {
        abort_unless($trip->driver_id === $request->user()->id, 404);

        return new TripResource($trip->load(['rider', 'vehicle']));
    }

    /**
     * POST /driver/trips/{trip}/navigation {destination: pickup|stop|dropoff} — ask before starting in-app
     * turn-by-turn (billed per destination). {in_app: true} = go ahead (counted); false = open Google Maps.
     * With check=true it only answers (nothing counted) — the app asks first, then shows Google's terms once.
     */
    public function navigation(Request $request, Trip $trip, NavigationPolicy $policy): JsonResponse
    {
        abort_unless($trip->driver_id === $request->user()->id && $trip->isActive(), 404);
        $data = $request->validate(['destination' => ['required', 'in:pickup,stop,dropoff'], 'check' => ['sometimes', 'boolean']]);
        $reason = ($data['check'] ?? false) ? $policy->blockedReason() : $policy->start($request->user(), $trip, $data['destination']);

        return response()->json(['in_app' => $reason === null, 'reason' => $reason]);
    }

    /** POST /driver/trips/{trip}/accept */
    public function accept(Request $request, Trip $trip): TripResource
    {
        return new TripResource($this->dispatch->accept($trip, $request->user())->load(['rider', 'driver.driverProfile', 'vehicle']));
    }

    /** POST /driver/trips/{trip}/decline */
    public function decline(Request $request, Trip $trip): JsonResponse
    {
        $this->dispatch->decline($trip, $request->user());

        return response()->json(['message' => 'Offer declined.']);
    }

    /** POST /driver/trips/{trip}/arrive */
    public function arrive(Request $request, Trip $trip): TripResource
    {
        return new TripResource($this->trips->arrive($trip, $request->user())->load(['rider', 'vehicle']));
    }

    /** POST /driver/trips/{trip}/start */
    public function start(Request $request, Trip $trip): TripResource
    {
        $data = $request->validate(['pin' => ['nullable', 'string', 'max:8']]);

        return new TripResource($this->trips->start($trip, $request->user(), $data['pin'] ?? null)->load(['rider', 'vehicle']));
    }

    /** POST /driver/trips/{trip}/stops/accept · /decline — answer the rider's stop change. */
    public function acceptStops(Request $request, Trip $trip): TripResource
    {
        return new TripResource($this->trips->respondToStopChange($trip, $request->user(), true)->load(['rider', 'vehicle']));
    }

    public function declineStops(Request $request, Trip $trip): TripResource
    {
        return new TripResource($this->trips->respondToStopChange($trip, $request->user(), false)->load(['rider', 'vehicle']));
    }

    /** POST /driver/trips/{trip}/stops/{index}/reached — arrived at a stop (0-based, in order). */
    public function reachStop(Request $request, Trip $trip, int $index): TripResource
    {
        return new TripResource($this->trips->reachStop($trip, $request->user(), $index)->load(['rider', 'vehicle']));
    }

    /** POST /driver/trips/{trip}/complete */
    public function complete(Request $request, Trip $trip): TripResource
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return new TripResource(
            $this->trips->complete($trip, $request->user(), $data['lat'] ?? null, $data['lng'] ?? null)->load(['rider', 'vehicle'])
        );
    }

    /** POST /driver/trips/{trip}/cancel */
    public function cancel(Request $request, Trip $trip): TripResource
    {
        abort_unless($trip->driver_id === $request->user()->id, 403, 'This is not your trip.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new TripResource($this->trips->cancel($trip, $request->user(), $data['reason'])->load(['rider', 'vehicle']));
    }
}
