<?php

namespace Tests\Feature;

use App\Jobs\DispatchTripJob;
use App\Jobs\SendPushJob;
use App\Models\DeviceToken;
use App\Models\ServiceArea;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DispatchService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Driver presence on the real Redis code (DB 15, test prefix; skipped without Redis). Reproduces 26 Oct: a driver's
 * pings paused, a ride request found them stale, and from then on they were never offered a ride although their
 * app still said ONLINE. Pings now restore everything, and the server clears drivers whose phone went silent.
 */
class DriverPresenceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceArea $bb;

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
        Queue::fake([SendPushJob::class, DispatchTripJob::class]);
        $this->bb = $this->seedReference();
    }

    protected function tearDown(): void
    {
        try {
            Redis::connection()->flushdb();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function premiumDriver(): User
    {
        $d = $this->makeApprovedDriver($this->bb);
        Vehicle::query()->whereKey($d->driverProfile->current_vehicle_id)->update(['vehicle_type' => 'premium']);
        $d->driverProfile->forceFill(['is_online' => false])->save();
        DeviceToken::query()->create(['user_id' => $d->id, 'token' => 'drv-'.$d->id, 'platform' => 'android']);

        return $d->fresh();
    }

    /** What POST /driver/online does (documents aside: compliance has its own tests). */
    private function goOnline(User $d): void
    {
        $d->driverProfile->forceFill(['is_online' => true])->save();
        app(\App\Services\DriverLocationService::class)->goOnline($d, 'premium');
    }

    private function ping(User $d): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($d, 'sanctum')->postJson('/api/v1/driver/location', ['lat' => self::BRIDGETOWN[0] + 0.002, 'lng' => self::BRIDGETOWN[1], 'heading' => 90]);
    }

    private function request(string $type = 'premium'): Trip
    {
        $rider = $this->makeUser();
        $id = $this->actingAs($rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['vehicle_type' => $type, 'payment_method' => 'cash']))
            ->assertCreated()->json('id');
        $trip = Trip::query()->where('public_id', $id)->first();
        app(DispatchService::class)->runWave($trip);

        return $trip;
    }

    public function test_a_gap_in_pings_no_longer_leaves_an_online_driver_without_rides(): void
    {
        $d = $this->premiumDriver();
        $this->goOnline($d);
        $this->ping($d)->assertOk()->assertJsonPath('online', true);
        $this->assertSame([$d->id], $this->request()->offers()->pluck('driver_id')->all(), 'offered while pinging');
        Trip::query()->update(['status' => 'cancelled']);
        app(\App\Services\DriverLocationService::class)->setState($d, 'idle');

        // The phone goes quiet for more than 90 s; a ride is requested meanwhile.
        Redis::connection()->del("driver:{$d->id}:hb");
        $this->assertSame([], $this->request()->offers()->pluck('driver_id')->all(), 'not offered while silent');
        Trip::query()->update(['status' => 'cancelled']);

        // Pings resume (the app still says ONLINE): the next request reaches them again.
        $this->ping($d)->assertOk()->assertJsonPath('online', true);
        $this->assertSame([$d->id], $this->request()->offers()->pluck('driver_id')->all(), 'back after one ping');

        $this->artisan('bentorm:drivers')->expectsOutputToContain('dispatchable ✓')->assertSuccessful();
    }

    public function test_offline_pings_are_ignored_and_silent_drivers_are_set_offline(): void
    {
        $d = $this->premiumDriver();
        // Marked offline: pinging doesn't make them dispatchable, and the reply says so.
        $this->ping($d)->assertOk()->assertJsonPath('online', false);
        $this->assertSame([], $this->request()->offers()->pluck('driver_id')->all());
        Trip::query()->update(['status' => 'cancelled']);

        // Online, then the phone goes silent for 10 minutes: set offline and told.
        $this->goOnline($d);
        $this->ping($d)->assertOk();
        Redis::connection()->del("driver:{$d->id}:hb");
        $this->artisan('bentorm:drivers')->expectsOutputToContain('NOT dispatchable: no ping')->assertSuccessful();
        $this->assertSame(0, app(TripService::class)->expireSilentDrivers(), 'not yet: under 10 minutes');
        $this->travel(11)->minutes();
        $this->assertSame(1, app(TripService::class)->expireSilentDrivers());
        $this->assertFalse($d->driverProfile->fresh()->is_online);
        Queue::assertPushed(SendPushJob::class, fn ($j) => str_contains(json_encode($j), 'offline'));
        $this->ping($d)->assertJsonPath('online', false); // the app sees this and signs back on
    }

    public function test_riders_see_free_cars_nearby_without_driver_details(): void
    {
        $d = $this->premiumDriver();
        $this->goOnline($d);
        $this->ping($d)->assertOk();
        $cars = $this->actingAs($this->makeUser(), 'sanctum')->getJson('/api/v1/drivers/nearby?lat='.self::BRIDGETOWN[0].'&lng='.self::BRIDGETOWN[1])
            ->assertOk()->json('cars');
        $this->assertSame([['lat' => round(self::BRIDGETOWN[0] + 0.002, 3), 'lng' => round(self::BRIDGETOWN[1], 3), 'heading' => 90, 'vehicle_type' => 'premium']], $cars);
        $this->getJson('/api/v1/drivers/nearby?lat='.self::BRIDGETOWN[0].'&lng='.self::BRIDGETOWN[1].'&vehicle_type=standard')->assertJsonPath('cars', []);
    }

    public function test_the_riders_trip_snapshot_has_the_route_to_draw(): void
    {
        $d = $this->premiumDriver();
        $this->goOnline($d);
        $this->ping($d)->assertOk();
        $trip = $this->request();
        $this->actingAs($d, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
        $this->ping($d)->assertOk();

        $snap = $this->actingAs($trip->rider, 'sanctum')->getJson("/api/v1/trips/{$trip->public_id}")->assertOk();
        $snap->assertJsonPath('route.phase', 'to_pickup')->assertJsonPath('driver_position.heading', 90.0);
        $this->assertGreaterThan(0, $snap->json('route.duration_s'));
        $this->assertSame('busy', Redis::connection()->hget("driver:{$d->id}", 'state'), 'on a job: busy, not offered other rides');
    }
}
