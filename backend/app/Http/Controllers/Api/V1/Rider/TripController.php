<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Http\Resources\RatingResource;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\DriverLocationService;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TripController extends Controller
{
    public function __construct(private readonly TripService $trips) {}

    /** GET /trips — rider history, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $trips = Trip::query()->where('rider_id', $request->user()->id)
            ->with(['driver.driverProfile', 'vehicle'])
            ->latest('id')->paginate(20);

        return TripResource::collection($trips);
    }

    /** GET /trips/active — the rider's current trip, if any. */
    public function active(Request $request, DriverLocationService $locations): JsonResponse
    {
        $trip = $request->user()->activeTrip();
        if (! $trip) {
            return response()->json(['trip' => null]);
        }
        $trip->load(['driver.driverProfile', 'vehicle']);

        return response()->json([
            'trip' => (new TripResource($trip))->resolve($request),
            'driver_position' => $trip->driver_id ? $locations->position($trip->driver_id) : null,
        ]);
    }

    /** POST /trips — request a ride. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_address' => ['nullable', 'string', 'max:255'],
            'vehicle_type' => ['required', 'string', 'max:32'],
            'payment_method' => ['nullable', 'in:cash,card'],
            'payment_method_id' => ['nullable', 'string', 'max:40'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:16'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:20'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(config('bentorm.vehicle_features')))],
            'rider_note' => ['nullable', 'string', 'max:500'],
            'stops' => ['nullable', 'array', 'max:'.config('bentorm.max_stops', 2)],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
        ]);

        $trip = $this->trips->request($request->user(), $data)->load(['rider', 'card']);

        // 201 searching for a driver · 202 card needs the rider (open payment_action.url) · 402 card hold declined
        return match ($trip->status->value) {
            'cancelled' => response()->json([
                'message' => 'Your card was declined. Please use another card or pay cash.',
                'trip' => (new TripResource($trip))->resolve($request),
            ], 402),
            'pending_payment' => (new TripResource($trip))->response()->setStatusCode(202),
            default => (new TripResource($trip))->response()->setStatusCode(201),
        };
    }

    /** GET /trips/{trip} */
    public function show(Request $request, Trip $trip, DriverLocationService $locations): JsonResponse
    {
        $this->authorizeView($request, $trip);
        $trip->load(['rider', 'driver.driverProfile', 'vehicle', 'ratings']);

        return response()->json([
            'trip' => (new TripResource($trip))->resolve($request),
            'driver_position' => $trip->isActive() && $trip->driver_id ? $locations->position($trip->driver_id) : null,
        ]);
    }

    /** POST /trips/{trip}/cancel */
    public function cancel(Request $request, Trip $trip): TripResource
    {
        $this->authorizeView($request, $trip);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new TripResource($this->trips->cancel($trip, $request->user(), $data['reason'] ?? null)->load(['rider', 'driver', 'vehicle']));
    }

    /** POST /trips/{trip}/rate */
    public function rate(Request $request, Trip $trip): RatingResource
    {
        $this->authorizeView($request, $trip);
        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        return new RatingResource($this->trips->rate($trip, $request->user(), $data['score'], $data['comment'] ?? null));
    }

    private function authorizeView(Request $request, Trip $trip): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $trip->involves($user)) {
            abort(403, 'This is not your trip.');
        }
    }

    /** POST /trips/{trip}/stops — replace the stops not yet reached. After a driver accepted, they must confirm. */
    public function changeStops(Request $request, Trip $trip): TripResource
    {
        $data = $request->validate([
            'stops' => ['present', 'array', 'max:'.config('bentorm.max_stops', 2)],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
        ]);

        return new TripResource($this->trips->changeStops($trip, $request->user(), $data['stops'] ?? [])->load(['rider', 'driver.driverProfile', 'vehicle', 'card']));
    }

    /** DELETE /trips/{trip}/stops/pending — withdraw a change the driver hasn't answered. */
    public function withdrawStopChange(Request $request, Trip $trip): TripResource
    {
        return new TripResource($this->trips->withdrawStopChange($trip, $request->user())->load(['rider', 'driver.driverProfile', 'vehicle', 'card']));
    }
}
