<?php

namespace Tests\Feature;

use App\Contracts\DistanceProvider;
use App\Support\Polyline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleMapsTest extends TestCase
{
    use RefreshDatabase;

    private function useGoogle(): void
    {
        config(['services.google_maps.server_key' => 'test-key', 'pricing.distance_provider' => 'google']);
        $this->app->forgetInstance(DistanceProvider::class);
    }

    public function test_polyline_decodes_to_lng_lat(): void
    {
        // Google's documented example.
        $this->assertSame([[-120.2, 38.5], [-120.95, 40.7], [-126.453, 43.252]], Polyline::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@'));
    }

    public function test_estimate_uses_google_routes_and_returns_route_once(): void
    {
        $this->seedReference();
        $this->useGoogle();
        Http::fake([
            'routes.googleapis.com/*' => Http::response(['routes' => [[
                'distanceMeters' => 12345, 'duration' => '1200s', 'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC'],
            ]]]),
        ]);

        $res = $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['vehicle_type' => null]));
        $res->assertOk()
            ->assertJsonPath('route.distance_m', 12345)
            ->assertJsonPath('route.duration_s', 1200)
            ->assertJsonPath('route.polyline.0', [38.5, -120.2]); // [lat, lng] for the apps
        $this->assertStringContainsString('12.3 km', collect($res->json('quotes.0.breakdown.lines'))->firstWhere('code', 'distance')['label']);

        // Quotes + route share one cached lookup; the key goes in a header, never the URL.
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-Goog-Api-Key', 'test-key') && ! str_contains($r->url(), 'test-key')
            && $r['travelMode'] === 'DRIVE');
    }

    public function test_routes_failure_falls_back_to_haversine(): void
    {
        $this->seedReference();
        $this->useGoogle();
        Http::fake(['routes.googleapis.com/*' => Http::response(['error' => ['code' => 403]], 403)]);

        $res = $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['vehicle_type' => null]));
        $res->assertOk()->assertJsonPath('route.polyline', null);
        $this->assertGreaterThan(5000, $res->json('route.distance_m'));
    }

    public function test_places_autocomplete_details_and_reverse(): void
    {
        $this->seedReference();
        $this->useGoogle();
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => [
                ['placePrediction' => ['placeId' => 'ChIJairport0001', 'text' => ['text' => 'Grantley Adams International Airport, Christ Church'],
                    'structuredFormat' => ['mainText' => ['text' => 'Grantley Adams International Airport'], 'secondaryText' => ['text' => 'Christ Church, Barbados']],
                    'distanceMeters' => 9100]],
                ['queryPrediction' => ['text' => ['text' => 'airport taxi']]], // non-place suggestions are dropped
            ]]),
            'places.googleapis.com/v1/places/*' => Http::response([
                'id' => 'ChIJairport0001', 'displayName' => ['text' => 'Grantley Adams International Airport'],
                'formattedAddress' => 'Christ Church, Barbados', 'location' => ['latitude' => 13.0746, 'longitude' => -59.4925],
            ]),
            'maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'OK', 'results' => [
                ['place_id' => 'ChIJstreet', 'formatted_address' => 'Bay Street, Bridgetown, Barbados'],
            ]]),
        ]);
        $rider = $this->makeUser();

        $this->getJson('/api/v1/places/autocomplete?q=airport')->assertUnauthorized();

        $this->actingAs($rider)->getJson('/api/v1/places/autocomplete?q=airport&lat=13.0969&lng=-59.6145&session=abc123')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.place_id', 'ChIJairport0001')
            ->assertJsonPath('0.main_text', 'Grantley Adams International Airport')
            ->assertJsonPath('0.distance_m', 9100);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'autocomplete')
            && isset($r['locationRestriction']['rectangle']['low']['latitude']) && $r['sessionToken'] === 'abc123'
            && count($r->header('X-Goog-FieldMask')) === 1 && count($r->header('X-Goog-Api-Key')) === 1);

        $this->actingAs($rider)->getJson('/api/v1/places/ChIJairport0001?session=abc123')
            ->assertOk()->assertJson(['name' => 'Grantley Adams International Airport', 'lat' => 13.0746, 'lng' => -59.4925]);
        // Google answers 400 if the field mask is anything but exactly the details fields (a merged
        // autocomplete + details mask broke this in production).
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/v1/places/ChIJairport0001')
            && $r->header('X-Goog-FieldMask') === ['id,displayName,formattedAddress,location']);

        $this->actingAs($rider)->getJson('/api/v1/places/reverse?lat=13.0969&lng=-59.6145')
            ->assertOk()->assertJson(['name' => 'Bay Street', 'address' => 'Bay Street, Bridgetown, Barbados']);
    }

    public function test_places_without_key_is_503_and_config_reports_it(): void
    {
        config(['services.google_maps.server_key' => null]);
        $this->actingAs($this->makeUser())->getJson('/api/v1/places/autocomplete?q=airport')->assertStatus(503);
        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('address_search', false)->assertJsonStructure(['realtime' => ['key', 'host', 'port', 'scheme', 'auth_endpoint']]);
    }

    public function test_google_error_becomes_503_not_500(): void
    {
        $this->useGoogle();
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'API key not valid']], 403)]);
        $this->actingAs($this->makeUser())->getJson('/api/v1/places/autocomplete?q=airport')->assertStatus(503);
    }
}
