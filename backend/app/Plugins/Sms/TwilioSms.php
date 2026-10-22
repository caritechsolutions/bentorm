<?php

namespace App\Plugins\Sms;

use App\Enums\GatewayType;
use App\Plugins\AbstractPlugin;
use App\Plugins\Contracts\SmsPlugin;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioSms extends AbstractPlugin implements SmsPlugin
{
    public static function key(): string { return 'twilio'; }

    public static function name(): string { return 'Twilio'; }

    public static function type(): GatewayType { return GatewayType::Sms; }

    public static function description(): string { return 'Twilio Programmable Messaging.'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'account_sid', 'label' => 'Account SID', 'type' => 'string', 'required' => true],
            ['key' => 'auth_token', 'label' => 'Auth token', 'type' => 'secret', 'required' => true],
            ['key' => 'from', 'label' => 'From number / sender ID', 'type' => 'string', 'help' => 'Leave empty if using a Messaging Service'],
            ['key' => 'messaging_service_sid', 'label' => 'Messaging Service SID', 'type' => 'string'],
        ];
    }

    public function send(string $to, string $message): void
    {
        $sid = $this->cfg('account_sid');
        $body = ['To' => $to, 'Body' => $message];
        if ($this->cfg('messaging_service_sid')) {
            $body['MessagingServiceSid'] = $this->cfg('messaging_service_sid');
        } else {
            $body['From'] = $this->cfg('from');
        }

        $res = Http::timeout(15)->asForm()->withBasicAuth($sid, (string) $this->cfg('auth_token'))
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $body);

        if (! $res->successful()) {
            throw new RuntimeException('Twilio '.$res->status().': '.($res->json('message') ?? mb_substr($res->body(), 0, 300)));
        }
    }

    public function test(array $context = []): array
    {
        try {
            if (! empty($context['to'])) {
                $this->send($context['to'], 'RideCoop test message');

                return ['ok' => true, 'message' => "Sent to {$context['to']}"];
            }
            $sid = $this->cfg('account_sid');
            $res = Http::timeout(10)->withBasicAuth($sid, (string) $this->cfg('auth_token'))
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");

            return $res->successful()
                ? ['ok' => true, 'message' => 'Credentials valid ('.$res->json('friendly_name').')']
                : ['ok' => false, 'message' => 'Twilio '.$res->status().': '.$res->json('message')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
