<?php

namespace App\Services\Google;

use App\Enums\AreaType;
use App\Models\ServiceArea;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Address search for the apps, done server-side so the key stays on the server and web clients
 * aren't blocked by CORS. Places API (New) for search/details, Geocoding API for reverse lookups.
 * Search is restricted to the bounding box of the active service areas.
 */
class PlacesService
{
    public const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
    public const DETAILS_URL = 'https://places.googleapis.com/v1/places/';
    public const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** @return array<int, array{place_id: string, main_text: string, secondary_text: ?string, distance_m: ?int}> */
    public function autocomplete(string $input, ?float $lat, ?float $lng, ?string $session): array
    {
        $body = ['input' => $input, 'languageCode' => 'en'];
        if ($session) {
            $body['sessionToken'] = $session;
        }
        if ($box = $this->serviceBox()) {
            $body['locationRestriction'] = ['rectangle' => [
                'low' => ['latitude' => $box[0], 'longitude' => $box[2]],
                'high' => ['latitude' => $box[1], 'longitude' => $box[3]],
            ]];
        }
        if ($lat !== null && $lng !== null) {
            $body['origin'] = ['latitude' => $lat, 'longitude' => $lng];
        }

        $res = $this->http()->post(self::AUTOCOMPLETE_URL, $body);
        $this->guard($res, 'autocomplete');

        return collect($res->json('suggestions', []))
            ->pluck('placePrediction')->filter()
            ->map(fn (array $p) => [
                'place_id' => $p['placeId'],
                'main_text' => $p['structuredFormat']['mainText']['text'] ?? ($p['text']['text'] ?? ''),
                'secondary_text' => $p['structuredFormat']['secondaryText']['text'] ?? null,
                'distance_m' => isset($p['distanceMeters']) ? (int) $p['distanceMeters'] : null,
            ])->values()->all();
    }

    /** @return array{place_id: string, name: string, address: ?string, lat: float, lng: float} */
    public function details(string $placeId, ?string $session): array
    {
        return Cache::remember('place:'.$placeId, now()->addDay(), function () use ($placeId, $session) {
            $res = $this->http()
                ->withHeaders(['X-Goog-FieldMask' => 'id,displayName,formattedAddress,location'])
                ->get(self::DETAILS_URL.rawurlencode($placeId), array_filter(['sessionToken' => $session, 'languageCode' => 'en']));
            $this->guard($res, 'details');

            return [
                'place_id' => $res->json('id', $placeId),
                'name' => $res->json('displayName.text') ?? $res->json('formattedAddress', ''),
                'address' => $res->json('formattedAddress'),
                'lat' => (float) $res->json('location.latitude'),
                'lng' => (float) $res->json('location.longitude'),
            ];
        });
    }

    /** Street address for a point (e.g. the rider's current location). Null when nothing is found. */
    public function reverse(float $lat, float $lng): ?array
    {
        $key = sprintf('revgeo:%.4f,%.4f', $lat, $lng);

        return Cache::remember($key, now()->addDay(), function () use ($lat, $lng) {
            $res = Http::timeout(6)->get(self::GEOCODE_URL, [
                'latlng' => "{$lat},{$lng}",
                'key' => config('services.google_maps.server_key'),
                'result_type' => 'street_address|premise|route|point_of_interest|establishment',
            ]);
            $this->guard($res, 'reverse');
            $r = $res->json('results.0');
            if (! $r) {
                return null;
            }
            $parts = explode(', ', (string) $r['formatted_address']);

            return [
                'place_id' => $r['place_id'] ?? null,
                'name' => $parts[0] ?? $r['formatted_address'],
                'address' => $r['formatted_address'],
                'lat' => $lat,
                'lng' => $lng,
            ];
        });
    }

    public static function configured(): bool
    {
        return (bool) config('services.google_maps.server_key');
    }

    private function http()
    {
        return Http::timeout(6)->withHeaders([
            'X-Goog-Api-Key' => (string) config('services.google_maps.server_key'),
            'X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text,'
                .'suggestions.placePrediction.structuredFormat,suggestions.placePrediction.distanceMeters',
        ]);
    }

    private function guard($res, string $what): void
    {
        if ($res->successful() && $res->json('status', 'OK') !== 'REQUEST_DENIED') {
            return;
        }
        Log::warning("Google Places {$what} failed", ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
        throw new ServiceUnavailableHttpException(null, 'Address search is unavailable right now.');
    }

    /** [minLat, maxLat, minLng, maxLng] covering every active service area, or null. */
    private function serviceBox(): ?array
    {
        return Cache::remember(ServiceArea::CACHE_KEY.':box', now()->addHour(), function () {
            $b = ServiceArea::query()->where('type', AreaType::Service->value)->where('is_active', true)
                ->selectRaw('MIN(min_lat) a, MAX(max_lat) b, MIN(min_lng) c, MAX(max_lng) d')->first();

            return $b && $b->a !== null ? [(float) $b->a, (float) $b->b, (float) $b->c, (float) $b->d] : null;
        });
    }
}
