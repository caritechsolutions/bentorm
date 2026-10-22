<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DriverLocationService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Exercises the real Redis GEO code. Uses Redis DB 15 with a test prefix so it never touches live data,
 * and skips when Redis isn't reachable.
 */
class DriverLocationRedisTest extends TestCase
{
    private DriverLocationService $loc;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.redis.default.database' => 15,
            'database.redis.options.prefix' => 'bentorm_test:',
        ]);
        Redis::purge('default');
        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis not available');
        }
        Redis::connection()->flushdb();
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

    private function driver(int $id): User
    {
        $u = new User(['name' => "D{$id}", 'phone' => "+1246000{$id}", 'role' => 'driver']);
        $u->id = $id;

        return $u;
    }

    public function test_nearby_idle_filters_by_state_type_and_distance(): void
    {
        $bridgetown = [13.0969, -59.6145];
        [$a, $b, $c, $far] = [$this->driver(1), $this->driver(2), $this->driver(3), $this->driver(4)];

        foreach ([$a, $b, $c, $far] as $d) {
            $this->loc->goOnline($d, 'standard');
        }
        $this->loc->goOnline($c, 'xl');
        $this->loc->update($a, 13.0980, -59.6140, 90, 5);   // ~130 m
        $this->loc->update($b, 13.1050, -59.6100);          // ~1 km
        $this->loc->update($c, 13.0975, -59.6150);          // close but XL
        $this->loc->update($far, 13.2500, -59.6400);        // ~17 km

        $near = $this->loc->nearbyIdle($bridgetown[0], $bridgetown[1], 8000, 'standard', 10);
        $this->assertSame([1, 2], array_column($near, 'driver_id'), 'nearest first, XL and far excluded');
        $this->assertLessThan($near[1]['distance_m'], $near[0]['distance_m']);

        $this->loc->setState($a, 'busy');
        $this->assertSame([2], array_column($this->loc->nearbyIdle($bridgetown[0], $bridgetown[1], 8000, 'standard', 10), 'driver_id'), 'busy excluded');

        $this->assertSame([2], array_column($this->loc->nearbyIdle($bridgetown[0], $bridgetown[1], 8000, 'standard', 10, [1]), 'driver_id'), 'exclusions honoured');
    }

    public function test_position_heartbeat_and_offline(): void
    {
        $d = $this->driver(7);
        $this->loc->goOnline($d, 'standard');
        $this->loc->update($d, 13.1, -59.6, 180.0, 12.5);

        $pos = $this->loc->position($d);
        $this->assertEqualsWithDelta(13.1, $pos['lat'], 0.0001);
        $this->assertSame(180.0, $pos['heading']);
        $this->assertTrue($this->loc->isOnline($d));
        $this->assertCount(1, $this->loc->allLive());

        $this->loc->goOffline($d);
        $this->assertFalse($this->loc->isOnline($d));
        $this->assertNull($this->loc->position($d));
        $this->assertSame([], $this->loc->nearbyIdle(13.1, -59.6, 8000, 'standard'));
    }

    public function test_drivers_with_expired_heartbeat_are_dropped(): void
    {
        $d = $this->driver(9);
        $this->loc->goOnline($d, 'standard');
        $this->loc->update($d, 13.1, -59.6);
        Redis::connection()->del('driver:9:hb'); // simulate the TTL lapsing

        $this->assertSame([], $this->loc->nearbyIdle(13.1, -59.6, 8000, 'standard'));
        $this->assertNull($this->loc->position($d), 'stale entry cleaned up');
    }
}
