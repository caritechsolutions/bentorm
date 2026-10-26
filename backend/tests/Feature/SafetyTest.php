<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Events\UserSignal;
use App\Jobs\SendPushJob;
use App\Models\Call;
use App\Models\DeviceToken;
use App\Models\Gateway;
use App\Models\SafetyAlert;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class SafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    private User $driver;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(['lat' => 13.09, 'lng' => -59.6, 'heading' => 90.0, 'speed' => null, 'state' => 'busy', 'vehicle_type' => 'standard', 'updated_at' => time()]);
        });
        $bb = $this->seedReference();
        $this->rider = $this->makeUser('rider', ['name' => 'Rita Rider']);
        $this->driver = $this->makeApprovedDriver($bb);
        foreach ([$this->rider, $this->driver] as $u) {
            DeviceToken::query()->create(['user_id' => $u->id, 'token' => 't'.$u->id, 'platform' => 'android']);
        }
        $this->trip = Trip::query()->create([
            'rider_id' => $this->rider->id, 'driver_id' => $this->driver->id, 'vehicle_id' => $this->driver->driverProfile->current_vehicle_id,
            'service_area_id' => $bb->id, 'vehicle_type' => 'standard', 'status' => TripStatus::Accepted, 'currency' => 'BBD',
            'pickup_lat' => 13.0969, 'pickup_lng' => -59.6145, 'pickup_address' => 'Bay Street', 'dropoff_lat' => 13.0667, 'dropoff_lng' => -59.5333, 'dropoff_address' => 'Oistins',
            'requested_at' => now(), 'accepted_at' => now(),
        ])->fresh();
    }

    public function test_share_my_trip_link_shows_it_live_without_login_then_expires(): void
    {
        $url = $this->actingAs($this->rider, 'sanctum')->postJson("/api/v1/trips/{$this->trip->public_id}/share")->assertOk()->json('url');
        $path = parse_url($url, PHP_URL_PATH);
        $this->actingAs($this->makeUser(), 'sanctum')->postJson("/api/v1/trips/{$this->trip->public_id}/share")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->get($path)->assertOk()->assertSee("Rita's ride", false);
        $live = $this->getJson($path.'.json')->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('vehicle', 'Toyota Corolla · Z'.$this->driver->id)->json();
        $this->assertEquals(['lat' => 13.09, 'lng' => -59.6, 'heading' => 90], $live['driver_position']);
        $this->assertArrayNotHasKey('phone', $live);

        $this->trip->forceFill(['status' => TripStatus::Completed, 'completed_at' => now()->subHours(2)])->save();
        $this->get($path)->assertNotFound();
        $this->get('/t/nope')->assertNotFound();
    }

    public function test_sos_alerts_every_admin_and_lists_emergency_numbers(): void
    {
        $admin = $this->makeUser('admin');
        DeviceToken::query()->create(['user_id' => $admin->id, 'token' => 'a', 'platform' => 'android']);
        $res = $this->actingAs($this->driver, 'sanctum')->postJson('/api/v1/sos', ['lat' => 13.1, 'lng' => -59.6])->assertOk();
        $this->assertSame(['Police', '211'], [$res->json('emergency_numbers.0.name'), $res->json('emergency_numbers.0.number')]);
        $this->assertNotNull($res->json('share_url'), 'the trip is shared with admins');
        $alert = SafetyAlert::query()->first();
        $this->assertSame(['driver', $this->trip->id, 'open'], [$alert->role, $alert->trip_id, $alert->status]);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $admin->id && $j->message->urgent && str_contains($j->message->body, 'Live: '));
        $this->getJson('/api/v1/config')->assertJsonPath('emergency_numbers.1.number', '511');

        $this->actingAs($admin, 'web')->get('/admin/safety-alerts')->assertOk()->assertSee($this->driver->name);
    }

    public function test_chat_between_rider_and_driver_during_the_trip(): void
    {
        Event::fake([UserSignal::class]);
        $this->actingAs($this->rider, 'sanctum')->postJson("/api/v1/trips/{$this->trip->public_id}/messages", ['body' => 'I\'m by the blue gate'])->assertCreated()->assertJsonPath('mine', true);
        Event::assertDispatched(UserSignal::class, fn ($e) => $e->userId === $this->driver->id && $e->type === 'chat.message');
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $this->driver->id && $j->message->title === 'Rita');

        $this->actingAs($this->driver, 'sanctum')->getJson("/api/v1/driver/trips/{$this->trip->public_id}")->assertJsonPath('unread_messages', 1)->assertJsonPath('can_chat', true)->assertJsonPath('can_call', true);
        $msgs = $this->getJson("/api/v1/trips/{$this->trip->public_id}/messages")->assertOk()->json('messages');
        $this->assertSame(['I\'m by the blue gate', false, 'Rita'], [$msgs[0]['body'], $msgs[0]['mine'], $msgs[0]['sender']]);
        $this->getJson("/api/v1/driver/trips/{$this->trip->public_id}")->assertJsonPath('unread_messages', 0);
        $this->postJson("/api/v1/trips/{$this->trip->public_id}/messages", ['body' => 'Pulling up now'])->assertCreated();
        $this->assertCount(1, $this->actingAs($this->rider, 'sanctum')->getJson("/api/v1/trips/{$this->trip->public_id}/messages?after={$msgs[0]['id']}")->json('messages'));
        $this->actingAs($this->makeUser(), 'sanctum')->getJson("/api/v1/trips/{$this->trip->public_id}/messages")->assertNotFound();

        $this->trip->forceFill(['status' => TripStatus::Completed])->save();
        $this->actingAs($this->rider, 'sanctum')->postJson("/api/v1/trips/{$this->trip->public_id}/messages", ['body' => 'Thanks'])->assertStatus(409);
    }

    public function test_video_call_rings_relays_webrtc_signalling_and_ends(): void
    {
        config(['bentorm.calls.turn' => ['turn:turn.example.com:3478'], 'bentorm.calls.turn_secret' => 's3cret']);
        Event::fake([UserSignal::class]);
        $call = $this->actingAs($this->rider, 'sanctum')->postJson("/api/v1/trips/{$this->trip->public_id}/calls", ['video' => true])->assertCreated()->json();
        $this->assertSame(['ringing', true, false], [$call['status'], $call['video'], $call['incoming']]);
        $turn = $call['ice_servers'][1];
        $this->assertSame(['turn:turn.example.com:3478'], $turn['urls']);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], 's3cret', true)), $turn['credential']);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $this->driver->id && $j->message->title === 'Video call from Rita' && $j->message->channel === 'ride_offers');
        $this->postJson("/api/v1/trips/{$this->trip->public_id}/calls")->assertCreated()->assertJsonPath('id', $call['id'], 'joins the live call');

        $this->postJson("/api/v1/calls/{$call['id']}/signal", ['type' => 'offer', 'payload' => ['sdp' => 'v=0…', 'type' => 'offer']])->assertOk();
        $this->actingAs($this->driver, 'sanctum')->getJson('/api/v1/calls/incoming')->assertJsonPath('call.id', $call['id'])->assertJsonPath('call.incoming', true)
            ->assertJsonPath('call.other_name', 'Rita');
        $this->postJson("/api/v1/calls/{$call['id']}/accept")->assertOk()->assertJsonPath('status', 'accepted');
        $sig = $this->getJson("/api/v1/calls/{$call['id']}/signals")->assertOk()->json('signals');
        $this->assertSame(['offer', 'v=0…'], [$sig[0]['type'], $sig[0]['payload']['sdp']]);
        $this->postJson("/api/v1/calls/{$call['id']}/signal", ['type' => 'answer', 'payload' => ['sdp' => 'answer', 'type' => 'answer']])->assertOk();
        $this->postJson("/api/v1/calls/{$call['id']}/signal", ['type' => 'candidate', 'payload' => ['candidate' => 'c1', 'sdpMid' => '0', 'sdpMLineIndex' => 0]])->assertOk();
        Event::assertDispatched(UserSignal::class, fn ($e) => $e->userId === $this->rider->id && $e->type === 'call.signal' && $e->data['type'] === 'candidate');
        $this->assertCount(2, $this->actingAs($this->rider, 'sanctum')->getJson("/api/v1/calls/{$call['id']}/signals")->json('signals'));
        $this->assertCount(1, $this->getJson("/api/v1/calls/{$call['id']}/signals?after=1")->json('signals'));

        $this->postJson("/api/v1/calls/{$call['id']}/end")->assertOk()->assertJsonPath('status', 'ended');
        $this->postJson("/api/v1/calls/{$call['id']}/signal", ['type' => 'candidate', 'payload' => ['x' => 1]])->assertStatus(409);
        $this->actingAs($this->makeUser(), 'sanctum')->getJson("/api/v1/calls/{$call['id']}")->assertNotFound();
    }

    public function test_unanswered_call_is_missed_and_phone_bridge_uses_twilio(): void
    {
        $id = $this->actingAs($this->driver, 'sanctum')->postJson("/api/v1/trips/{$this->trip->public_id}/calls")->assertCreated()->json('id');
        $this->travel(46)->seconds();
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/calls/incoming')->assertJsonPath('call', null);
        $this->assertSame('missed', Call::query()->where('public_id', $id)->value('status'));

        $id2 = $this->actingAs($this->driver, 'sanctum')->postJson("/api/v1/trips/{$this->trip->public_id}/calls")->json('id');
        $this->postJson("/api/v1/calls/{$id2}/phone")->assertStatus(409)->assertJsonPath('message', 'Phone calls are not set up. Please use chat.');
        Gateway::query()->create(['type' => 'sms', 'plugin' => 'twilio', 'name' => 'Twilio', 'is_active' => true, 'priority' => 10,
            'config' => ['account_sid' => 'AC1', 'auth_token' => 'tok', 'voice_from' => '+12465550100']]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);
        $this->postJson("/api/v1/calls/{$id2}/phone")->assertOk()->assertJsonPath('status', 'bridged')->assertJsonPath('via', 'phone');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Calls.json') && $r['To'] === $this->driver->phone && str_contains($r['Twiml'], $this->rider->phone)
            && $r['From'] === '+12465550100');

        Features::put('masked_calls', false);
        $this->trip->refresh();
        $this->postJson("/api/v1/trips/{$this->trip->public_id}/calls")->assertForbidden();
    }

    public function test_call_diagnostics_show_what_each_phone_offered_and_turn_test_prints_a_login(): void
    {
        config(['bentorm.calls.turn' => ['turn:turn.ridecoop.test:3478?transport=udp'], 'bentorm.calls.turn_secret' => 'secret']);
        $call = $this->actingAs($this->rider, 'sanctum')->postJson("/api/v1/trips/{$this->trip->public_id}/calls", ['video' => true])->assertCreated()->json();
        $sdp = "v=0\r\na=candidate:1 1 udp 2122260223 192.168.1.20 50000 typ host\r\na=candidate:2 1 udp 1686052607 200.1.2.3 50000 typ srflx raddr 192.168.1.20 rport 50000\r\n";
        $this->postJson("/api/v1/calls/{$call['id']}/signal", ['type' => 'offer', 'payload' => ['sdp' => $sdp, 'type' => 'offer']])->assertOk();
        $this->postJson("/api/v1/calls/{$call['id']}/signal", ['type' => 'candidate', 'payload' => ['candidate' => 'candidate:3 1 udp 41885439 203.0.113.9 49170 typ relay raddr 200.1.2.3 rport 50000', 'sdpMid' => '0']])->assertOk();

        $this->postJson("/api/v1/calls/{$call['id']}/signal", ['type' => 'state', 'payload' => ['state' => 'media_ok', 'detail' => 'video']])->assertOk();
        $this->artisan('bentorm:calls')
            ->expectsOutputToContain('not answered')
            ->expectsOutputToContain('caller: offer ✓ · no answer · host 1 · srflx 1 · prflx 0 · relay 1 (udp)')
            ->expectsOutputToContain('phone said: media_ok (video)')
            ->expectsOutputToContain('callee: nothing kept')
            ->assertSuccessful();

        $this->artisan('bentorm:turn-test')->expectsOutputToContain('check-turn.sh')->expectsOutputToContain('turn.ridecoop.test')->assertSuccessful();
        config(['bentorm.calls.turn' => []]);
        $this->artisan('bentorm:turn-test')->assertFailed();
    }
}
