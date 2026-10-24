<?php

namespace Tests\Feature;

use App\Enums\GatewayType;
use App\Jobs\SendPushJob;
use App\Models\DeviceToken;
use App\Models\Gateway;
use App\Models\Trip;
use App\Models\TripOffer;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\Push\FcmPush;
use App\Services\DriverLocationService;
use App\Services\PushService;
use App\Support\PushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/ride-coop/messages:send';

    private function serviceAccount(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return json_encode(['type' => 'service_account', 'project_id' => 'ride-coop', 'private_key' => $pem,
            'client_email' => 'firebase-adminsdk-x@ride-coop.iam.gserviceaccount.com', 'token_uri' => self::TOKEN_URL]);
    }

    private function gateway(): Gateway
    {
        $plugins = app(PluginManager::class);

        return Gateway::query()->create(['type' => GatewayType::Push, 'plugin' => 'fcm', 'name' => 'Push', 'is_active' => true,
            'config' => $plugins->prepareConfig('fcm', ['service_account' => $this->serviceAccount()])]);
    }

    private function trip(User $rider, ?User $driver = null, string $status = 'requested'): Trip
    {
        $bb = \App\Models\ServiceArea::query()->where('code', 'BB')->first();

        return Trip::query()->create([
            'rider_id' => $rider->id, 'driver_id' => $driver?->id, 'vehicle_id' => $driver?->driverProfile?->current_vehicle_id,
            'service_area_id' => $bb?->id, 'status' => $status, 'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'pickup_address' => 'Bay Street',
            'dropoff_lat' => 13.07, 'dropoff_lng' => -59.53, 'estimated_fare' => 40,
        ]);
    }

    public function test_fcm_signs_in_with_the_service_account_and_sends_a_loud_offer(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            self::SEND_URL => Http::response(['name' => 'projects/ride-coop/messages/1']),
        ]);
        $plugin = app(PluginManager::class)->make($this->gateway());
        $this->assertInstanceOf(FcmPush::class, $plugin);

        $ok = $plugin->send('device-token-1', new PushMessage('New ride request', 'Pickup: Bay Street', ['trip_id' => 'abc', 'offer_id' => 7],
            channel: 'ride_offers', ttlSeconds: 20, collapseKey: 'offer-abc', urgent: true));
        $plugin->send('device-token-2', new PushMessage('Hi', 'Again'));
        $this->assertTrue($ok);

        Http::assertSentCount(3); // one sign-in (cached), two sends
        Http::assertSent(function ($r) {
            if ($r->url() !== self::TOKEN_URL) {
                return false;
            }
            [$h, $claims] = array_map(fn ($p) => json_decode(base64_decode(strtr($p, '-_', '+/')), true), array_slice(explode('.', $r['assertion']), 0, 2));

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $h['alg'] === 'RS256'
                && $claims['scope'] === FcmPush::SCOPE && $claims['iss'] === 'firebase-adminsdk-x@ride-coop.iam.gserviceaccount.com';
        });
        Http::assertSent(fn ($r) => $r->url() === self::SEND_URL && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['token'] === 'device-token-1'
            && $r['message']['android']['priority'] === 'HIGH'
            && $r['message']['android']['ttl'] === '20s'
            && $r['message']['android']['notification']['channel_id'] === 'ride_offers'
            && $r['message']['android']['notification']['sound'] === 'ride_offer'
            && $r['message']['data'] === ['trip_id' => 'abc', 'offer_id' => '7', 'channel' => 'ride_offers']);
        $this->assertSame(['ok' => true, 'message' => 'Credentials valid (Firebase project ride-coop)'], $plugin->test());
    }

    public function test_dead_tokens_are_forgotten_and_other_errors_keep_them(): void
    {
        $this->seedReference();
        $this->gateway();
        $user = $this->makeUser();
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'dead', 'platform' => 'android']);
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'alive', 'platform' => 'android']);
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'flaky', 'platform' => 'android']);
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'ya29.test']),
            self::SEND_URL => function ($r) {
                return match ($r['message']['token']) {
                    'dead' => Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.',
                        'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404),
                    'flaky' => Http::response(['error' => ['code' => 503, 'message' => 'Unavailable']], 503),
                    default => Http::response(['name' => 'ok']),
                };
            },
        ]);

        $this->assertSame(1, app(PushService::class)->deliver($user->id, new PushMessage('t', 'b')));
        $this->assertEqualsCanonicalizing(['alive', 'flaky'], DeviceToken::query()->pluck('token')->all());
        $this->assertNotNull(DeviceToken::query()->where('token', 'alive')->value('last_used_at'));
    }

    public function test_no_push_gateway_means_nothing_is_sent(): void
    {
        $this->seedReference();
        $user = $this->makeUser();
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 't', 'platform' => 'android']);
        Http::fake();
        $this->assertSame(0, app(PushService::class)->deliver($user->id, new PushMessage('t', 'b')));
        Http::assertNothingSent();
    }

    public function test_ride_events_notify_the_right_person(): void
    {
        Queue::fake();
        $this->seedReference();
        $this->mock(DriverLocationService::class, fn (MockInterface $m) => $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull());
        $rider = $this->makeUser('rider');
        $driver = $this->makeApprovedDriver();
        foreach ([$rider, $driver] as $u) {
            DeviceToken::query()->create(['user_id' => $u->id, 'token' => "tok-{$u->id}", 'platform' => 'android']);
        }
        $trip = $this->trip($rider);

        // A ride offer rings the driver.
        TripOffer::query()->create(['trip_id' => $trip->id, 'driver_id' => $driver->id, 'distance_m' => 900, 'offered_at' => now(), 'expires_at' => now()->addSeconds(20)]);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $driver->id && $j->message->channel === 'ride_offers'
            && $j->message->urgent && $j->message->data['trip_id'] === $trip->public_id && $j->message->ttlSeconds <= 20
            && str_contains($j->message->body, 'Bay Street'));

        // Accepted / arrived → rider.
        $trip->forceFill(['driver_id' => $driver->id, 'vehicle_id' => $driver->driverProfile->current_vehicle_id, 'status' => 'accepted'])->save();
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $rider->id && str_ends_with($j->message->title, 'is on the way') && str_contains($j->message->body, 'Toyota Corolla'));
        $trip->forceFill(['status' => 'arrived'])->save();
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $rider->id && str_ends_with($j->message->title, 'has arrived'));

        // Stop change → driver; answer → rider.
        $trip->forceFill(['stop_change' => ['status' => 'pending', 'stops' => []]])->save();
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $driver->id && $j->message->title === 'Stop change requested');
        $trip->forceFill(['stop_change' => ['status' => 'declined', 'stops' => []]])->save();
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $rider->id && $j->message->title === 'Stops not changed');

        // Rider cancels → driver.
        $this->actingAs($rider)->postJson("/api/v1/trips/{$trip->public_id}/cancel")->assertOk();
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $driver->id && $j->message->title === 'Ride cancelled');
    }

    public function test_an_admin_cancelling_their_own_ride_is_labelled_rider(): void
    {
        Queue::fake();
        $this->seedReference();
        $admin = $this->makeUser('admin');
        $trip = $this->trip($admin);
        $this->actingAs($admin)->postJson("/api/v1/trips/{$trip->public_id}/cancel")->assertOk();
        $this->assertSame('rider', $trip->fresh()->cancelled_by);
    }

    public function test_device_tokens_follow_the_signed_in_account(): void
    {
        $this->seedReference();
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($a)->postJson('/api/v1/devices', ['token' => 'phone-1', 'platform' => 'android'])->assertOk();
        $this->actingAs($b)->postJson('/api/v1/devices', ['token' => 'phone-1', 'platform' => 'android'])->assertOk();
        $this->assertSame([$b->id], DeviceToken::query()->where('token', 'phone-1')->pluck('user_id')->all());

        $this->actingAs($b)->deleteJson('/api/v1/devices', ['token' => 'phone-1'])->assertOk();
        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_installer_command_reads_the_key_from_a_file_and_tests_it(): void
    {
        $this->seedReference();
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'ya29.test'])]);
        $file = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($file, $this->serviceAccount());

        $this->artisan('bentorm:gateway', ['plugin' => 'fcm', '--name' => 'Push (Firebase)', '--set-file' => ["service_account={$file}"], '--test' => true])
            ->assertSuccessful();
        unlink($file);
        $g = Gateway::query()->where('plugin', 'fcm')->firstOrFail();
        $this->assertSame(GatewayType::Push, $g->type);
        $this->assertStringStartsWith('OK', (string) $g->last_test_result);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) \Illuminate\Support\Facades\DB::table('gateways')->where('id', $g->id)->value('config'), 'stored encrypted');
        $this->assertSame('Push (Firebase)', app(PluginManager::class)->gatewayFor(GatewayType::Push)?->name);
    }
}
