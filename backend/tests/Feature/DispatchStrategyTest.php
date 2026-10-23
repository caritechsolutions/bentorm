<?php

namespace Tests\Feature;

use App\Contracts\DistanceProvider;
use App\Enums\OfferResponse;
use App\Enums\TripStatus;
use App\Filament\Resources\DispatchSettings\Pages\CreateDispatchSetting;
use App\Filament\Resources\DispatchSettings\Pages\EditDispatchSetting;
use App\Models\DispatchSetting;
use App\Models\ServiceArea;
use App\Models\Trip;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\DriverLocationService;
use App\Services\Routing\GoogleRoutesDistanceProvider;
use App\Services\Routing\HaversineDistanceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dispatch strategies against the real Redis GEO code (DB 15, test prefix; skipped without Redis).
 * Drivers are placed due north of the Bridgetown pickup: 0.009° latitude ≈ 1 km.
 */
class DispatchStrategyTest extends TestCase
{
    use RefreshDatabase;

    private ServiceArea $bb;

    private DriverLocationService $loc;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.default.database' => 15, 'database.redis.options.prefix' => 'bentorm_test:']);
        Redis::purge('default');
        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis not available');
        }
        Redis::connection()->flushdb();
        Queue::fake();
        $this->bb = $this->seedReference();
        $this->loc = app(DriverLocationService::class);
    }

    protected function tearDown(): void
    {
        try {
            Redis::connection()->flushdb();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    /** An online, idle driver $km north of the pickup, waiting since $idleSecondsAgo. */
    private function driverAt(float $km, int $idleSecondsAgo = 0): User
    {
        $d = $this->makeApprovedDriver($this->bb);
        $this->loc->goOnline($d, 'standard');
        $this->loc->update($d, self::BRIDGETOWN[0] + $km * 0.009, self::BRIDGETOWN[1], vehicleType: 'standard');
        if ($idleSecondsAgo) {
            Redis::hset("driver:{$d->id}", 'idle_since', now()->timestamp - $idleSecondsAgo);
        }

        return $d;
    }

    private function settings(array $s): void
    {
        DispatchSetting::query()->whereNull('service_area_id')->update($s);
    }

    private function request(): Trip
    {
        $rider = $this->makeUser('rider');
        $res = $this->actingAs($rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS))->assertCreated();

        return Trip::query()->where('public_id', $res->json('id'))->firstOrFail();
    }

    private function wave(Trip $trip): void
    {
        app(DispatchService::class)->runWave($trip);
    }

    /** @return array<int, int> driver ids with a pending offer */
    private function offered(Trip $trip): array
    {
        return $trip->offers()->where('response', OfferResponse::Pending->value)->orderBy('id')->pluck('driver_id')->all();
    }

    private function expireOffers(Trip $trip): void
    {
        $trip->offers()->where('response', OfferResponse::Pending->value)->update(['expires_at' => now()->subSecond()]);
    }

    public function test_default_setting_exists_and_an_area_setting_overrides_it(): void
    {
        $default = DispatchSetting::forArea($this->bb->id);
        $this->assertTrue($default->isDefault());
        $this->assertSame(['waves', 'distance', 3, 20, 4, 3000, 3000, 12000], [$default->strategy, $default->ranking, $default->batch_size,
            $default->offer_ttl_seconds, $default->max_waves, $default->radius_start_m, $default->radius_step_m, $default->radius_max_m]);
        $this->assertSame([3000, 6000, 9000, 12000, 12000], array_map(fn ($n) => $default->radiusForWave($n), [0, 1, 2, 3, 9]));
        $this->assertSame('3 km → 12 km (+3 km each time)', $default->radiusSummary());

        DispatchSetting::query()->create(['service_area_id' => $this->bb->id, 'strategy' => 'broadcast', 'ranking' => 'longest_idle']);
        $this->assertSame('broadcast', DispatchSetting::forArea($this->bb->id)->strategy);
        $this->assertTrue(DispatchSetting::forArea(null)->isDefault());
    }

    public function test_waves_offer_the_nearest_batch_then_the_next(): void
    {
        $this->settings(['batch_size' => 2, 'radius_start_m' => 10000, 'radius_step_m' => 0, 'radius_max_m' => 10000]);
        $near = $this->driverAt(0.5);
        $mid = $this->driverAt(1.5);
        $far = $this->driverAt(4);
        $trip = $this->request();

        $this->wave($trip);
        $this->assertSame([$near->id, $mid->id], $this->offered($trip));
        $this->assertSame(1, $trip->offers()->first()->wave);
        $this->assertSame(10000, $trip->offers()->first()->radius_m);

        // Both let it time out: the next wave goes to the next nearest, not to them again.
        $this->expireOffers($trip);
        $this->wave($trip);
        $this->assertSame([$far->id], $this->offered($trip));
        $this->assertSame(2, $trip->offers()->where('driver_id', $far->id)->value('wave'));
    }

    public function test_an_empty_ring_widens_at_once_without_using_a_wave(): void
    {
        $this->settings(['batch_size' => 3, 'max_waves' => 4]);  // 3 → 6 → 9 → 12 km
        $five = $this->driverAt(5);
        $trip = $this->request();

        $this->wave($trip);
        $offer = $trip->offers()->firstOrFail();
        $this->assertSame($five->id, $offer->driver_id);
        $this->assertSame(6000, $offer->radius_m, 'nobody within 3 km → searched 6 km in the same go');
        $this->assertSame(1, $offer->wave, 'the empty 3 km search did not use up a wave');

        // Declines → the ring keeps widening; a driver at 8 km is found next.
        $eight = $this->driverAt(8);
        $this->actingAs($five)->postJson("/api/v1/driver/trips/{$trip->public_id}/decline")->assertOk();
        $this->wave($trip);
        $this->assertSame([$eight->id], $this->offered($trip));
        $this->assertSame(9000, $trip->offers()->where('driver_id', $eight->id)->value('radius_m'));
    }

    public function test_nobody_anywhere_ends_after_the_set_number_of_waves(): void
    {
        $this->settings(['max_waves' => 2]);
        $this->driverAt(30); // outside 12 km
        $trip = $this->request();

        $this->wave($trip); // 3, 6, 9, 12 km all empty → uses wave 1 at the widest ring, waits
        $this->assertSame(TripStatus::Requested, $trip->fresh()->status);
        $this->wave($trip); // wave 2, still nobody
        $this->assertSame(TripStatus::Requested, $trip->fresh()->status);

        // A driver comes online in time for... nothing: both waves are used.
        $this->wave($trip);
        $this->assertSame(TripStatus::NoDrivers, $trip->fresh()->status);
    }

    public function test_a_driver_coming_online_is_found_on_a_later_wave(): void
    {
        $this->settings(['max_waves' => 3]);
        $trip = $this->request();
        $this->wave($trip);
        $this->assertSame([], $this->offered($trip));

        $late = $this->driverAt(10);
        $this->wave($trip);
        $this->assertSame([$late->id], $this->offered($trip));
    }

    public function test_broadcast_offers_everyone_in_range_at_once(): void
    {
        $this->settings(['strategy' => 'broadcast', 'broadcast_limit' => 3, 'radius_start_m' => 5000, 'radius_step_m' => 0, 'radius_max_m' => 5000]);
        $a = $this->driverAt(1);
        $b = $this->driverAt(2);
        $c = $this->driverAt(3);
        $d = $this->driverAt(4);
        $this->driverAt(7); // out of range
        $trip = $this->request();

        $this->wave($trip);
        $this->assertSame([$a->id, $b->id, $c->id], $this->offered($trip), 'capped at the broadcast limit, nearest first');

        // First to accept wins; the others' offers are withdrawn.
        $this->actingAs($c)->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
        $this->assertSame($c->id, $trip->fresh()->driver_id);
        $this->assertSame(OfferResponse::Withdrawn, $trip->offers()->where('driver_id', $a->id)->first()->response);
        $this->assertSame(0, $trip->offers()->where('driver_id', $d->id)->count());
    }

    public function test_longest_waiting_driver_goes_first(): void
    {
        $this->settings(['batch_size' => 1, 'ranking' => 'longest_idle', 'radius_start_m' => 10000, 'radius_step_m' => 0, 'radius_max_m' => 10000]);
        $this->driverAt(0.5, idleSecondsAgo: 60);
        $patient = $this->driverAt(6, idleSecondsAgo: 1800);
        $this->driverAt(2, idleSecondsAgo: 600);
        $trip = $this->request();

        $this->wave($trip);
        $this->assertSame([$patient->id], $this->offered($trip));
    }

    public function test_going_online_and_finishing_a_ride_restart_the_waiting_clock(): void
    {
        $d = $this->makeApprovedDriver($this->bb);
        $this->travelTo(now()->subHour());
        $this->loc->goOnline($d, 'standard');
        $this->travelBack();
        $this->assertEqualsWithDelta(now()->subHour()->timestamp, (int) Redis::hget("driver:{$d->id}", 'idle_since'), 2);

        $this->loc->setState($d, 'busy');
        $this->loc->setState($d, 'idle');
        $this->assertEqualsWithDelta(now()->timestamp, (int) Redis::hget("driver:{$d->id}", 'idle_since'), 2);
    }

    public function test_fastest_arrival_by_road_uses_the_route_matrix(): void
    {
        config(['services.google_maps.server_key' => 'test-key']);
        $this->app->instance(DistanceProvider::class, new GoogleRoutesDistanceProvider(new HaversineDistanceProvider));
        $this->settings(['batch_size' => 1, 'ranking' => 'eta', 'eta_candidates' => 2, 'radius_start_m' => 10000, 'radius_step_m' => 0, 'radius_max_m' => 10000]);
        $nearButSlow = $this->driverAt(1);   // origin 0: across the gully
        $fartherButQuick = $this->driverAt(3); // origin 1
        $this->driverAt(5);                  // not checked (only the nearest 2)
        $trip = $this->request();

        Http::fake([GoogleRoutesDistanceProvider::MATRIX_URL => Http::response([
            ['destinationIndex' => 0, 'duration' => '900s', 'condition' => 'ROUTE_EXISTS'], // originIndex 0 is omitted by Google
            ['originIndex' => 1, 'destinationIndex' => 0, 'duration' => '240s', 'condition' => 'ROUTE_EXISTS'],
        ])]);
        $this->wave($trip);

        $this->assertSame([$fartherButQuick->id], $this->offered($trip));
        $this->assertSame(240, $trip->offers()->first()->eta_s);
        Http::assertSent(fn ($r) => $r->url() === GoogleRoutesDistanceProvider::MATRIX_URL && count($r['origins']) === 2
            && $r['destinations'][0]['waypoint']['location']['latLng']['latitude'] == self::BRIDGETOWN[0]);

        // The driver sees the arrival time on the offer.
        $this->actingAs($fartherButQuick)->getJson('/api/v1/driver/offers')->assertOk()->assertJsonPath('offers.0.eta_s', 240);
        $this->assertNotContains($nearButSlow->id, $this->offered($trip));
    }

    public function test_route_matrix_failure_falls_back_to_straight_line_estimates(): void
    {
        config(['services.google_maps.server_key' => 'test-key']);
        $this->app->instance(DistanceProvider::class, new GoogleRoutesDistanceProvider(new HaversineDistanceProvider));
        $this->settings(['batch_size' => 1, 'ranking' => 'eta', 'radius_start_m' => 10000, 'radius_step_m' => 0, 'radius_max_m' => 10000]);
        $near = $this->driverAt(1);
        $this->driverAt(3);
        $trip = $this->request();
        Http::fake([GoogleRoutesDistanceProvider::MATRIX_URL => Http::response(['error' => ['message' => 'API not enabled']], 403)]);

        $this->wave($trip);
        $this->assertSame([$near->id], $this->offered($trip));
        $this->assertNotNull($trip->offers()->first()->eta_s);
    }

    public function test_admin_edits_the_default_and_adds_an_area_override(): void
    {
        $this->actingAs($this->makeUser('admin'));
        $default = DispatchSetting::query()->whereNull('service_area_id')->firstOrFail();

        Livewire::test(EditDispatchSetting::class, ['record' => $default->getRouteKey()])
            ->assertFormSet(['radius_start_m' => 3.0, 'radius_max_m' => 12.0])
            ->fillForm(['strategy' => 'broadcast', 'broadcast_limit' => 10, 'radius_start_m' => 2.5, 'radius_step_m' => 2.5, 'radius_max_m' => 10, 'max_waves' => 3, 'offer_ttl_seconds' => 15])
            ->call('save')
            ->assertHasNoFormErrors();
        $default->refresh();
        $this->assertSame(['broadcast', 10, 2500, 2500, 10000, 3, 15, null], [$default->strategy, $default->broadcast_limit, $default->radius_start_m,
            $default->radius_step_m, $default->radius_max_m, $default->max_waves, $default->offer_ttl_seconds, $default->service_area_id]);

        // Max radius below start is refused.
        Livewire::test(EditDispatchSetting::class, ['record' => $default->getRouteKey()])
            ->fillForm(['radius_start_m' => 8, 'radius_max_m' => 4])->call('save')->assertHasFormErrors(['radius_max_m']);

        // A new area setting starts from the default's values.
        Livewire::test(CreateDispatchSetting::class)
            ->assertFormSet(['strategy' => 'broadcast', 'radius_start_m' => 2.5])
            ->fillForm(['service_area_id' => $this->bb->id, 'strategy' => 'waves', 'ranking' => 'longest_idle', 'batch_size' => 1])
            ->call('create')
            ->assertHasNoFormErrors();
        $area = DispatchSetting::forArea($this->bb->id);
        $this->assertSame([$this->bb->id, 'waves', 'longest_idle', 1, 2500], [$area->service_area_id, $area->strategy, $area->ranking, $area->batch_size, $area->radius_start_m]);

        // Only one setting per area.
        Livewire::test(CreateDispatchSetting::class)
            ->fillForm(['service_area_id' => $this->bb->id])->call('create')->assertHasFormErrors(['service_area_id' => 'unique']);
    }
}
