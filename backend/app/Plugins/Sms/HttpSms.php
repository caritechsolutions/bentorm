<?php

namespace App\Plugins\Sms;

use App\Enums\GatewayType;
use App\Plugins\AbstractPlugin;
use App\Plugins\Contracts\SmsPlugin;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Any SMS provider with a simple HTTP POST API — field names are configurable. */
class HttpSms extends AbstractPlugin implements SmsPlugin
{
    public static function key(): string { return 'http_sms'; }

    public static function name(): string { return 'Generic HTTP SMS'; }

    public static function type(): GatewayType { return GatewayType::Sms; }

    public static function description(): string { return 'Posts to any SMS provider HTTP API. Configure the endpoint and field names.'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'url', 'required' => true],
            ['key' => 'format', 'label' => 'Body format', 'type' => 'select', 'options' => ['json', 'form'], 'default' => 'json'],
            ['key' => 'auth_header', 'label' => 'Auth header name', 'type' => 'string', 'default' => 'Authorization'],
            ['key' => 'auth_value', 'label' => 'Auth header value', 'type' => 'secret', 'help' => 'e.g. "Bearer abc123"'],
            ['key' => 'to_field', 'label' => 'Recipient field', 'type' => 'string', 'default' => 'to'],
            ['key' => 'message_field', 'label' => 'Message field', 'type' => 'string', 'default' => 'message'],
            ['key' => 'sender_field', 'label' => 'Sender field', 'type' => 'string', 'default' => 'from'],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'string', 'default' => 'Ride Coop'],
            ['key' => 'strip_plus', 'label' => 'Send number without "+"', 'type' => 'bool', 'default' => false],
            ['key' => 'extra_fields', 'label' => 'Extra fields (JSON object)', 'type' => 'text', 'help' => '{"api_key":"..."}'],
        ];
    }

    public function send(string $to, string $message): void
    {
        $payload = json_decode((string) $this->cfg('extra_fields', '{}'), true) ?: [];
        $payload[$this->cfg('to_field', 'to')] = $this->cfg('strip_plus') ? ltrim($to, '+') : $to;
        $payload[$this->cfg('message_field', 'message')] = $message;
        if ($this->cfg('sender_field') && $this->cfg('sender_id')) {
            $payload[$this->cfg('sender_field')] = $this->cfg('sender_id');
        }

        $req = Http::timeout(15);
        if ($this->cfg('auth_header') && $this->cfg('auth_value')) {
            $req = $req->withHeaders([$this->cfg('auth_header') => $this->cfg('auth_value')]);
        }
        $res = $this->cfg('format') === 'form' ? $req->asForm()->post($this->cfg('endpoint'), $payload) : $req->asJson()->post($this->cfg('endpoint'), $payload);

        if (! $res->successful()) {
            throw new RuntimeException('SMS gateway HTTP '.$res->status().': '.mb_substr($res->body(), 0, 300));
        }
    }

    public function test(array $context = []): array
    {
        if (empty($context['to'])) {
            return ['ok' => false, 'message' => 'Provide a phone number to send a test SMS.'];
        }
        try {
            $this->send($context['to'], config('bentorm.brand_name', 'Ride Coop').' test message');

            return ['ok' => true, 'message' => "Sent to {$context['to']}"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
