<?php

namespace App\Plugins\Sms;

use App\Enums\GatewayType;
use App\Plugins\AbstractPlugin;
use App\Plugins\Contracts\SmsPlugin;
use Illuminate\Support\Facades\Cache;
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
            ['key' => 'from', 'label' => 'From number / sender ID', 'type' => 'string',
                'help' => 'Leave empty to use the first SMS-capable number on the Twilio account (or set a Messaging Service)'],
            ['key' => 'messaging_service_sid', 'label' => 'Messaging Service SID', 'type' => 'string'],
            ['key' => 'voice_from', 'label' => 'Voice number (phone-bridge calls)', 'type' => 'string',
                'help' => 'Leave empty to use the first voice-capable number on the account. Used when an in-app call can\'t connect.'],
        ];
    }

    public function send(string $to, string $message): void
    {
        $sid = $this->cfg('account_sid');
        $body = ['To' => $to, 'Body' => $message];
        if ($this->cfg('messaging_service_sid')) {
            $body['MessagingServiceSid'] = $this->cfg('messaging_service_sid');
        } else {
            $body['From'] = $this->sender();
        }

        $res = $this->http()->asForm()->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $body);

        if (! $res->successful()) {
            throw new RuntimeException('Twilio '.$res->status().': '.($res->json('message') ?? mb_substr($res->body(), 0, 300)));
        }
    }

    /** The configured From, else the first SMS-capable number on the account (looked up once a day). */
    public function sender(): string
    {
        if ($from = $this->cfg('from')) {
            return (string) $from;
        }
        $sid = (string) $this->cfg('account_sid');

        return Cache::remember('twilio:sender:'.$sid, now()->addDay(), function () use ($sid) {
            $res = $this->http()->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}/IncomingPhoneNumbers.json", ['PageSize' => 50]);
            if (! $res->successful()) {
                throw new RuntimeException('Twilio '.$res->status().': '.($res->json('message') ?? 'could not list phone numbers'));
            }
            $number = collect($res->json('incoming_phone_numbers', []))
                ->first(fn ($n) => ($n['capabilities']['sms'] ?? false) === true);
            if (! $number) {
                throw new RuntimeException('This Twilio account has no SMS-capable phone number. Buy one in Twilio (Phone Numbers → Buy a number) or set a Messaging Service SID.');
            }

            return (string) $number['phone_number'];
        });
    }

    public function test(array $context = []): array
    {
        try {
            if (! empty($context['to'])) {
                $this->send($context['to'], config('bentorm.brand_name', 'Ride Coop').' test message');

                return ['ok' => true, 'message' => "Sent to {$context['to']}"];
            }
            $sid = $this->cfg('account_sid');
            $res = $this->http()->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
            if (! $res->successful()) {
                return ['ok' => false, 'message' => 'Twilio '.$res->status().': '.$res->json('message')];
            }
            $via = $this->cfg('messaging_service_sid') ? 'Messaging Service '.$this->cfg('messaging_service_sid') : $this->sender();

            return ['ok' => true, 'message' => 'Credentials valid ('.$res->json('friendly_name').'); sending from '.$via];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Phone-bridge call (fallback when the in-app call can't connect): Twilio rings $first, then connects them to
     * $second, showing the Twilio number to both — neither sees the other's real number.
     */
    public function bridge(string $first, string $second): string
    {
        $sid = $this->cfg('account_sid');
        $from = $this->voiceNumber();
        $twiml = '<Response><Say>Connecting your '.e(config('bentorm.brand_name', 'Ride Coop')).' call.</Say><Dial callerId="'.e($from).'" timeout="30">'
            .e($second).'</Dial></Response>';
        $res = $this->http()->asForm()->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Calls.json", ['To' => $first, 'From' => $from, 'Twiml' => $twiml]);
        if (! $res->successful()) {
            throw new RuntimeException('Twilio '.$res->status().': '.($res->json('message') ?? mb_substr($res->body(), 0, 300)));
        }

        return (string) $res->json('sid');
    }

    /** The configured voice number, else the first voice-capable number on the account (looked up once a day). */
    public function voiceNumber(): string
    {
        if ($from = $this->cfg('voice_from')) {
            return (string) $from;
        }
        $sid = (string) $this->cfg('account_sid');

        return Cache::remember('twilio:voice:'.$sid, now()->addDay(), function () use ($sid) {
            $res = $this->http()->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}/IncomingPhoneNumbers.json", ['PageSize' => 50]);
            $number = collect($res->json('incoming_phone_numbers', []))->first(fn ($n) => ($n['capabilities']['voice'] ?? false) === true);
            if (! $res->successful() || ! $number) {
                throw new RuntimeException('No voice-capable Twilio number. Buy one in Twilio (Phone Numbers → Buy a number, with Voice).');
            }

            return (string) $number['phone_number'];
        });
    }

    private function http()
    {
        return Http::timeout(15)->withBasicAuth((string) $this->cfg('account_sid'), (string) $this->cfg('auth_token'));
    }
}
