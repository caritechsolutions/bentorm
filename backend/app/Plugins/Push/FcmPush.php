<?php

namespace App\Plugins\Push;

use App\Enums\GatewayType;
use App\Plugins\AbstractPlugin;
use App\Plugins\Contracts\PushPlugin;
use App\Support\PushMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging (HTTP v1). Configured with the project's service-account key
 * (Firebase console → Project settings → Service accounts → Generate new private key).
 */
class FcmPush extends AbstractPlugin implements PushPlugin
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public static function key(): string { return 'fcm'; }

    public static function name(): string { return 'Firebase Cloud Messaging'; }

    public static function type(): GatewayType { return GatewayType::Push; }

    public static function description(): string { return 'Push notifications to the Android (and later iOS) apps through Firebase.'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'service_account', 'label' => 'Service account key (JSON)', 'type' => 'secret', 'required' => true,
                'help' => 'Firebase console → Project settings → Service accounts → Generate new private key. Paste the whole file.'],
        ];
    }

    /** @return array{project_id: string, client_email: string, private_key: string, token_uri: string} */
    private function account(): array
    {
        $json = json_decode((string) $this->cfg('service_account', ''), true);
        if (! is_array($json) || empty($json['project_id']) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('The Firebase service account key is missing or not valid JSON.');
        }

        return [
            'project_id' => (string) $json['project_id'],
            'client_email' => (string) $json['client_email'],
            'private_key' => (string) $json['private_key'],
            'token_uri' => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /** OAuth access token from a self-signed JWT (cached ~50 minutes; Google issues them for an hour). */
    public function accessToken(): string
    {
        $a = $this->account();

        return Cache::remember('fcm:token:'.sha1($a['client_email'].$a['private_key']), now()->addMinutes(50), function () use ($a) {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $now = time();
            $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                'iss' => $a['client_email'], 'scope' => self::SCOPE, 'aud' => $a['token_uri'], 'iat' => $now, 'exp' => $now + 3600,
            ]));
            $key = openssl_pkey_get_private($a['private_key']);
            if ($key === false || ! openssl_sign($unsigned, $sig, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign with the Firebase private key.');
            }
            $res = Http::asForm()->timeout(10)->post($a['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$b64($sig),
            ]);
            if (! $res->successful() || ! $res->json('access_token')) {
                throw new RuntimeException('Firebase sign-in failed ('.$res->status().'): '.($res->json('error_description') ?? $res->json('error') ?? mb_substr($res->body(), 0, 200)));
            }

            return (string) $res->json('access_token');
        });
    }

    public function payload(string $token, PushMessage $m): array
    {
        $android = ['priority' => $m->urgent ? 'HIGH' : 'NORMAL', 'notification' => array_filter([
            'channel_id' => $m->channel,
            'sound' => $m->channel === 'ride_offers' ? 'ride_offer' : 'default',
            'notification_priority' => $m->urgent ? 'PRIORITY_MAX' : 'PRIORITY_DEFAULT',
            'default_vibrate_timings' => true,
            'tag' => $m->collapseKey,
        ], fn ($v) => $v !== null)];
        if ($m->ttlSeconds !== null) {
            $android['ttl'] = max(0, $m->ttlSeconds).'s';
        }
        if ($m->collapseKey !== null) {
            $android['collapse_key'] = $m->collapseKey;
        }

        return ['message' => [
            'token' => $token,
            'notification' => ['title' => $m->title, 'body' => $m->body],
            'data' => array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? ''), $m->data + ['channel' => $m->channel]),
            'android' => $android,
            'apns' => ['headers' => array_filter(['apns-priority' => $m->urgent ? '10' : '5',
                'apns-expiration' => $m->ttlSeconds !== null ? (string) (time() + $m->ttlSeconds) : null]),
                'payload' => ['aps' => ['sound' => 'default'] + ($m->urgent ? ['interruption-level' => 'time-sensitive'] : [])]],
        ]];
    }

    public function send(string $token, PushMessage $message, bool $validateOnly = false): bool
    {
        $a = $this->account();
        $body = $this->payload($token, $message) + ($validateOnly ? ['validate_only' => true] : []);
        $res = Http::withToken($this->accessToken())->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$a['project_id']}/messages:send", $body);
        if ($res->successful()) {
            return true;
        }
        $code = collect($res->json('error.details', []))->firstWhere('@type', 'type.googleapis.com/google.firebase.fcm.v1.FcmError')['errorCode'] ?? null;
        // The app was uninstalled or the token rotated: forget it.
        if ($res->status() === 404 || $code === 'UNREGISTERED' || ($res->status() === 400 && $code === 'INVALID_ARGUMENT' && str_contains((string) $res->json('error.message'), 'token'))) {
            return false;
        }
        throw new RuntimeException('FCM '.$res->status().': '.($res->json('error.message') ?? mb_substr($res->body(), 0, 300)));
    }

    public function test(array $context = []): array
    {
        try {
            $a = $this->account();
            $this->accessToken();
            if (! empty($context['token'])) {
                $ok = $this->send($context['token'], new PushMessage('Ride Coop test', 'Push notifications are working.'));

                return ['ok' => $ok, 'message' => $ok ? 'Sent to that device' : 'That device token is no longer valid'];
            }

            return ['ok' => true, 'message' => "Credentials valid (Firebase project {$a['project_id']})"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
