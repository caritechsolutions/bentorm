<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Google\PlacesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Address search for the apps (Google Places, proxied so the key stays on the server). */
class PlaceController extends Controller
{
    public function __construct(private readonly PlacesService $places) {}

    /** GET /places/autocomplete?q=&lat=&lng=&session= */
    public function autocomplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'session' => ['nullable', 'string', 'max:64'],
        ]);
        $this->ensureConfigured();

        return response()->json($this->places->autocomplete(
            trim($data['q']),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
            $data['session'] ?? null,
        ));
    }

    /** GET /places/reverse?lat=&lng= — address for a point (current location / dragged pin). */
    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);
        $this->ensureConfigured();
        $place = $this->places->reverse((float) $data['lat'], (float) $data['lng']);

        return $place ? response()->json($place) : response()->json(['message' => 'No address found here.'], 404);
    }

    /** GET /places/nearby?lat=&lng= — named places within ~250 m, nearest first. */
    public function nearby(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);
        $this->ensureConfigured();

        return response()->json($this->places->nearby((float) $data['lat'], (float) $data['lng']));
    }

    /** GET /places/{placeId}?session= — coordinates and address for a chosen suggestion. */
    public function show(Request $request, string $placeId): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{10,300}$/', $placeId), 404);
        $data = $request->validate(['session' => ['nullable', 'string', 'max:64']]);
        $this->ensureConfigured();

        return response()->json($this->places->details($placeId, $data['session'] ?? null));
    }

    private function ensureConfigured(): void
    {
        abort_unless(PlacesService::configured(), 503, 'Address search is not configured (GOOGLE_MAPS_SERVER_KEY).');
    }
}
