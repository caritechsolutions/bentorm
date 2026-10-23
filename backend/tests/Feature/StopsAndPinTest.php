<?php

namespace Tests\Feature;

use App\Enums\FareRuleKind;
use App\Models\FareRule;
use App\Models\ServiceArea;
use App\Models\Trip;
use App\Models\TripOffer;
use App\Models\User;
use App\Services\DriverLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class StopsAndPinTest extends TestCase
{
    use RefreshDatabase;

    private const STOP_GARRISON = [13.0790, -59.6080];

    private const STOP_WARRENS = [13.1450, -59.6000];

    private User $rider;

    private User $driver;

    private ServiceArea $bb;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->bb = $this->seedReference();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });
        $this->rider = $this->makeUser('rider');
        $this->driver = $this->makeApprovedDriver($this->bb);
    }

    private function stops(array ...$points): array
    {
        return array_map(fn ($p) => ['lat' => $p[0], 'lng' => $p[1], 'address' => "Stop {$p[0]}"], $points);
    }

    private function quote(array $stops = []): array
    {
        return $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['stops' => $stops, 'vehicle_type' => null]))
            ->assertOk()->json();
    }

    private function request(array $stops = []): Trip
    {
        $res = $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['stops' => $stops, 'payment_method' => 'cash']));
        $res->assertCreated();

        return Trip::query()->where('public_id', $res->json('id'))->firstOrFail();
    }

    private function accept(Trip $trip): void
    {
        TripOffer::query()->create(['trip_id' => $trip->id, 'driver_id' => $this->driver->id, 'distance_m' => 300, 'offered_at' => now(), 'expires_at' => now()->addSeconds(20)]);
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
    }

    private function line(array $quote, string $code): ?array
    {
        return collect($quote['breakdown']['lines'])->firstWhere('code', $code);
    }

    // ---------------------------------------------------------------- pricing

    public function test_stops_lengthen_the_route_and_add_the_per_stop_fee(): void
    {
        $plain = $this->quote();
        $one = $this->quote($this->stops(self::STOP_GARRISON));
        $two = $this->quote($this->stops(self::STOP_GARRISON, self::STOP_WARRENS));

        $p = collect($plain['quotes'])->firstWhere('vehicle_type', 'standard');
        $o = collect($one['quotes'])->firstWhere('vehicle_type', 'standard');
        $t = collect($two['quotes'])->firstWhere('vehicle_type', 'standard');

        $this->assertNull($this->line($p, 'stops'));
        $this->assertSame(['code' => 'stops', 'label' => 'Extra stop', 'amount' => 2.0], $this->line($o, 'stops'));
        $this->assertSame(['code' => 'stops', 'label' => 'Extra stops (2)', 'amount' => 4.0], $this->line($t, 'stops'));
        $this->assertGreaterThan($p['distance_m'], $o['distance_m']);
        $this->assertGreaterThan($o['distance_m'], $t['distance_m']);
        $this->assertGreaterThan($p['fare'], $o['fare']);
        $this->assertGreaterThan($one['route']['distance_m'], $two['route']['distance_m']);
        // XL has its own per-stop fee
        $this->assertSame(3.0, $this->line(collect($one['quotes'])->firstWhere('vehicle_type', 'xl'), 'stops')['amount']);
    }

    public function test_stop_limits_and_geofence(): void
    {
        $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['stops' => $this->stops(self::STOP_GARRISON, self::STOP_WARRENS, self::HOLETOWN)]))
            ->assertStatus(422)->assertJsonValidationErrors('stops');
        $res = $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['stops' => $this->stops(self::ST_LUCIA)]))->assertStatus(422);
        $this->assertSame(['Stop 1 must be within Barbados.'], $res->json('errors')['stops.0']);
    }

    public function test_fixed_route_does_not_apply_when_the_ride_has_stops(): void
    {
        $airport = ServiceArea::query()->where('name', 'Grantley Adams International Airport')->firstOrFail();
        $port = ServiceArea::query()->where('name', 'like', 'Bridgetown Port%')->firstOrFail();
        FareRule::query()->create(['service_area_id' => $this->bb->id, 'name' => 'Airport ⇄ Port', 'kind' => FareRuleKind::FixedRoute,
            'amount' => 60, 'pickup_zone_id' => $airport->id, 'dropoff_zone_id' => $port->id, 'bidirectional' => true, 'is_active' => true]);
        $portPoint = [13.1040, -59.6310];

        $direct = $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::AIRPORT, $portPoint))->assertOk()->json('quotes.0');
        $this->assertNotNull($this->line($direct, 'fixed_route'));

        $viaStop = $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::AIRPORT, $portPoint, ['stops' => $this->stops(self::OISTINS)]))->assertOk()->json('quotes.0');
        $this->assertNull($this->line($viaStop, 'fixed_route'));
        $this->assertNotNull($this->line($viaStop, 'distance'));
    }

    // ---------------------------------------------------------------- PIN

    public function test_pin_is_shown_to_the_rider_only_and_required_to_start(): void
    {
        $trip = $this->request();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $trip->pin);

        $this->actingAs($this->rider)->getJson("/api/v1/trips/{$trip->public_id}")->assertOk()->assertJsonPath('trip.pin', $trip->pin);
        $this->accept($trip);
        $this->actingAs($this->driver)->getJson("/api/v1/driver/trips/{$trip->public_id}")->assertOk()->assertJsonMissingPath('pin');
        $this->actingAs($this->driver)->getJson('/api/v1/driver/trips/active')->assertOk()->assertJsonMissingPath('trip.pin');
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk();

        $start = "/api/v1/driver/trips/{$trip->public_id}/start";
        $this->actingAs($this->driver)->postJson($start)->assertStatus(422)->assertJsonPath('errors.pin.0', 'Ask the rider for their 4-digit PIN.');
        $wrong = $trip->pin === '0000' ? '1111' : '0000';
        $this->actingAs($this->driver)->postJson($start, ['pin' => $wrong])->assertStatus(422)->assertJsonPath('errors.pin.0', 'That PIN is not right. 4 tries left.');

        $this->actingAs($this->driver)->postJson($start, ['pin' => $trip->pin])->assertOk()->assertJsonPath('status', 'in_progress')->assertJsonPath('pin_verified', true);
        $this->assertNotNull($trip->fresh()->pin_verified_at);
        // Once the trip is under way the PIN is no longer needed anywhere.
        $this->actingAs($this->rider)->getJson("/api/v1/trips/{$trip->public_id}")->assertJsonPath('trip.pin', $trip->pin);
    }

    public function test_too_many_wrong_pins_lock_starting_for_a_while(): void
    {
        $trip = $this->request();
        $this->accept($trip);
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk();
        $start = "/api/v1/driver/trips/{$trip->public_id}/start";
        $wrong = $trip->pin === '0000' ? '1111' : '0000';
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->driver)->postJson($start, ['pin' => $wrong])->assertStatus(422);
        }
        $this->actingAs($this->driver)->postJson($start, ['pin' => $trip->pin])->assertStatus(429);

        $this->travel(3)->minutes();
        $this->actingAs($this->driver)->postJson($start, ['pin' => $trip->pin])->assertOk();
    }

    public function test_pin_never_goes_out_in_broadcasts_or_offers(): void
    {
        $trip = $this->request();
        // Even when built while the rider is the signed-in user (they triggered the update).
        $this->actingAs($this->rider);
        $payload = (new \App\Events\TripUpdated($trip->fresh()))->broadcastWith();
        $this->assertArrayNotHasKey('pin', $payload['trip']);
        $this->assertArrayNotHasKey('phone', $payload['trip']['rider']);

        $offer = TripOffer::query()->create(['trip_id' => $trip->id, 'driver_id' => $this->driver->id, 'distance_m' => 300, 'offered_at' => now(), 'expires_at' => now()->addSeconds(20)]);
        $this->assertArrayNotHasKey('pin', (new \App\Events\TripOffered($offer))->broadcastWith()['trip']);
        $this->actingAs($this->driver)->getJson('/api/v1/driver/offers')->assertOk()->assertJsonMissingPath('offers.0.trip.pin');
    }

    // ---------------------------------------------------------------- stops on a trip

    public function test_request_with_stops_and_driver_reaches_them_in_order(): void
    {
        $trip = $this->request($this->stops(self::STOP_GARRISON, self::STOP_WARRENS));
        $this->assertCount(2, $trip->stops);
        $this->assertSame('Extra stops (2)', collect($trip->fare_breakdown['lines'])->firstWhere('code', 'stops')['label']);

        $this->accept($trip);
        $offerView = $this->actingAs($this->driver)->getJson("/api/v1/driver/trips/{$trip->public_id}")->assertOk();
        $offerView->assertJsonCount(2, 'stops')->assertJsonPath('stops.0.reached_at', null);

        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/0/reached")->assertStatus(409); // not started
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk();
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/start", ['pin' => $trip->pin])->assertOk();
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/1/reached")->assertStatus(409); // out of order
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/0/reached")->assertOk()->assertJsonPath('stops.0.reached_at', fn ($v) => $v !== null);
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/1/reached")->assertOk();
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/2/reached")->assertNotFound();

        $done = $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/complete", ['lat' => self::OISTINS[0], 'lng' => self::OISTINS[1]])
            ->assertOk()->json();
        $this->assertEquals(4.0, collect($done['fare_breakdown']['lines'])->firstWhere('code', 'stops')['amount'], 'stop fee on the final fare');
    }

    public function test_changing_stops_before_a_driver_accepts_applies_at_once(): void
    {
        $trip = $this->request();
        $before = $trip->estimated_fare;
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/stops", ['stops' => $this->stops(self::STOP_GARRISON)])
            ->assertOk()->assertJsonCount(1, 'stops')->assertJsonPath('stop_change', null);
        $this->assertGreaterThan($before, $trip->fresh()->estimated_fare);
    }

    public function test_mid_ride_change_waits_for_the_driver(): void
    {
        $trip = $this->request();
        $this->accept($trip);
        $fareBefore = $trip->fresh()->estimated_fare;

        // Rider asks for a stop → pending; nothing changes yet.
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/stops", ['stops' => $this->stops(self::STOP_GARRISON)])
            ->assertOk()->assertJsonPath('stop_change.status', 'pending')->assertJsonCount(0, 'stops')
            ->assertJsonPath('stop_change.estimated_fare', fn ($f) => $f > $fareBefore);
        $this->actingAs($this->driver)->getJson("/api/v1/driver/trips/{$trip->public_id}")->assertJsonPath('stop_change.status', 'pending');

        // Driver declines → unchanged.
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/decline")
            ->assertOk()->assertJsonPath('stop_change.status', 'declined')->assertJsonCount(0, 'stops');
        $this->assertSame($fareBefore, $trip->fresh()->estimated_fare);
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/accept")->assertStatus(409);

        // Asks again, driver accepts → applied, estimate goes up.
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/stops", ['stops' => $this->stops(self::STOP_GARRISON)])->assertOk();
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/accept")
            ->assertOk()->assertJsonPath('stop_change.status', 'accepted')->assertJsonCount(1, 'stops');
        $this->assertGreaterThan($fareBefore, $trip->fresh()->estimated_fare);
    }

    public function test_reached_stops_are_kept_when_the_rider_changes_the_rest(): void
    {
        $trip = $this->request($this->stops(self::STOP_GARRISON, self::STOP_WARRENS));
        $this->accept($trip);
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk();
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/start", ['pin' => $trip->pin])->assertOk();
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/0/reached")->assertOk();

        // Drop the second stop: the reached first one stays.
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/stops", ['stops' => []])->assertOk()
            ->assertJsonCount(1, 'stop_change.stops');
        $this->actingAs($this->driver)->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/accept")->assertOk()
            ->assertJsonCount(1, 'stops')->assertJsonPath('stops.0.reached_at', fn ($v) => $v !== null);

        // A third stop would exceed the limit once the reached one is counted.
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/stops", ['stops' => $this->stops(self::STOP_WARRENS, self::HOLETOWN)])
            ->assertStatus(422)->assertJsonValidationErrors('stops');
    }

    public function test_rider_can_withdraw_a_pending_change_and_others_cannot_touch_it(): void
    {
        $trip = $this->request();
        $this->accept($trip);
        $this->actingAs($this->makeUser())->postJson("/api/v1/trips/{$trip->public_id}/stops", ['stops' => []])->assertForbidden();
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$trip->public_id}/stops", ['stops' => $this->stops(self::STOP_GARRISON)])->assertOk();
        $this->actingAs($this->rider)->deleteJson("/api/v1/trips/{$trip->public_id}/stops/pending")->assertOk()->assertJsonPath('stop_change', null);
        $this->actingAs($this->makeApprovedDriver($this->bb))->postJson("/api/v1/driver/trips/{$trip->public_id}/stops/accept")->assertForbidden();
    }
}
