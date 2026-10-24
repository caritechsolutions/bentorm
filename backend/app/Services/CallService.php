<?php

namespace App\Services;

use App\Events\UserSignal;
use App\Models\Call;
use App\Models\Trip;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\Sms\TwilioSms;
use App\Support\Features;
use App\Support\PushMessage;
use Illuminate\Support\Facades\Cache;

/**
 * In-app audio/video calls between rider (or customer) and driver, so phone numbers stay private. The server only
 * rings the other side and relays WebRTC signalling (offer, answer, ICE candidates); media flows phone to phone,
 * through the TURN relay when a network blocks a direct path. If the call can't connect, a Twilio phone bridge
 * connects the two phones through a Ride Coop number.
 */
class CallService
{
    public function __construct(private readonly PushService $push, private readonly PluginManager $plugins) {}

    public function canCall(Trip $trip): bool
    {
        return Features::on('masked_calls') && $trip->driver_id !== null && in_array($trip->status->value, ['accepted', 'arrived', 'in_progress'], true);
    }

    public function start(Trip $trip, User $caller, bool $video): Call
    {
        Features::require('masked_calls', 'Calling');
        abort_unless($trip->involves($caller), 404);
        abort_unless($this->canCall($trip), 409, 'Calls are open while a driver is on the way and during the trip.');
        $this->expireRinging();
        if ($live = Call::query()->where('trip_id', $trip->id)->whereIn('status', ['ringing', 'accepted'])->first()) {
            return $live; // one call at a time: join the one already going
        }
        $callee = $caller->id === $trip->driver_id ? $trip->rider_id : $trip->driver_id;
        $call = Call::query()->create(['trip_id' => $trip->id, 'caller_id' => $caller->id, 'callee_id' => $callee, 'video' => $video])->refresh();
        $ring = ['call_id' => $call->public_id, 'trip_id' => $trip->public_id, 'from' => $caller->firstName(), 'video' => $video];
        event(new UserSignal($callee, 'call.ring', $ring));
        $this->push->notify($callee, new PushMessage(($video ? 'Video call' : 'Call').' from '.$caller->firstName(), 'Tap to answer in Ride Coop.',
            ['type' => 'call'] + $ring, channel: 'ride_offers', ttlSeconds: Call::RING_SECONDS, collapseKey: 'call-'.$call->public_id, urgent: true));

        return $call;
    }

    /** STUN + short-lived TURN credentials (coturn "use-auth-secret": user = expiry:id, password = HMAC-SHA1). */
    public function iceServers(User $user): array
    {
        $servers = [['urls' => array_values(config('bentorm.calls.stun'))]];
        $turn = array_values(config('bentorm.calls.turn'));
        if ($turn && ($secret = config('bentorm.calls.turn_secret'))) {
            $username = (now()->timestamp + (int) config('bentorm.calls.turn_ttl')).':'.$user->id;
            $servers[] = ['urls' => $turn, 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true))];
        }

        return $servers;
    }

    public function answer(Call $call, User $user, bool $accept): Call
    {
        abort_unless($call->callee_id === $user->id, 404);
        $this->expireRinging();
        $call->refresh();
        abort_unless($call->status === 'ringing', 409, 'This call has ended.');
        $call->forceFill($accept ? ['status' => 'accepted', 'answered_at' => now()] : ['status' => 'declined', 'ended_at' => now()])->save();
        $this->tell($call, $call->caller_id);

        return $call;
    }

    public function end(Call $call, User $user): Call
    {
        abort_unless($call->involves($user), 404);
        if ($call->isLive()) {
            $missed = $call->status === 'ringing' && $user->id === $call->caller_id;
            $call->forceFill(['status' => $missed ? 'missed' : 'ended', 'ended_at' => now()])->save();
            $this->tell($call, $call->otherThan($user));
            if ($missed) {
                $this->push->notify($call->callee_id, new PushMessage('Missed call', 'You missed a call from '.$call->caller->firstName().'. Open Ride Coop to call back or chat.',
                    ['type' => 'call_missed', 'trip_id' => $call->trip->public_id], collapseKey: 'call-'.$call->public_id));
            }
        }

        return $call;
    }

    /** Relay one WebRTC message (offer | answer | candidate) to the other side. */
    public function signal(Call $call, User $from, string $type, array $payload): void
    {
        abort_unless($call->involves($from), 404);
        abort_unless($call->isLive(), 409, 'This call has ended.');
        $to = $call->otherThan($from);
        $key = "call:{$call->id}:to:{$to}";
        $list = Cache::get($key, []);
        $seq = count($list) + 1;
        $list[] = ['seq' => $seq, 'type' => $type, 'payload' => $payload];
        Cache::put($key, array_slice($list, -200), now()->addHour());
        event(new UserSignal($to, 'call.signal', ['call_id' => $call->public_id, 'seq' => $seq, 'type' => $type, 'payload' => $payload]));
    }

    /** Signalling messages waiting for $user after $after (for apps without a live connection). */
    public function signals(Call $call, User $user, int $after = 0): array
    {
        abort_unless($call->involves($user), 404);

        return array_values(array_filter(Cache::get("call:{$call->id}:to:{$user->id}", []), fn ($m) => $m['seq'] > $after));
    }

    /** Fallback: Twilio rings the person asking, then connects them to the other person. */
    public function phoneBridge(Call $call, User $user): Call
    {
        abort_unless($call->involves($user), 404);
        $sms = $this->plugins->sms($call->trip->service_area_id);
        abort_unless($sms instanceof TwilioSms, 409, 'Phone calls are not set up. Please use chat.');
        $other = User::query()->find($call->otherThan($user));
        try {
            $sms->bridge($user->phone, $other->phone);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'We couldn\'t place the phone call. Please use chat.');
        }
        $call->forceFill(['status' => 'bridged', 'via' => 'phone', 'answered_at' => $call->answered_at ?? now()])->save();
        $this->tell($call, $other->id);

        return $call;
    }

    /** Calls nobody answered in time become "missed". */
    public function expireRinging(): void
    {
        Call::query()->where('status', 'ringing')->where('created_at', '<', now()->subSeconds(Call::RING_SECONDS))->get()
            ->each(function (Call $c) {
                $c->forceFill(['status' => 'missed', 'ended_at' => now()])->save();
                $this->tell($c, $c->caller_id);
            });
    }

    public function toApi(Call $call, User $viewer): array
    {
        $other = User::query()->find($call->otherThan($viewer));

        return ['id' => $call->public_id, 'trip_id' => $call->trip->public_id, 'status' => $call->status, 'video' => $call->video, 'via' => $call->via,
            'incoming' => $call->callee_id === $viewer->id, 'other_name' => $other?->firstName(), 'ice_servers' => $this->iceServers($viewer),
            'ring_seconds' => Call::RING_SECONDS, 'phone_bridge' => $this->plugins->sms($call->trip->service_area_id) instanceof TwilioSms];
    }

    private function tell(Call $call, int $userId): void
    {
        event(new UserSignal($userId, 'call.updated', ['call_id' => $call->public_id, 'status' => $call->status]));
    }
}
