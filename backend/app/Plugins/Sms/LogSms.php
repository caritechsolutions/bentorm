<?php

namespace App\Plugins\Sms;

use App\Enums\GatewayType;
use App\Plugins\AbstractPlugin;
use App\Plugins\Contracts\SmsPlugin;
use Illuminate\Support\Facades\Log;

class LogSms extends AbstractPlugin implements SmsPlugin
{
    public static function key(): string { return 'log_sms'; }

    public static function name(): string { return 'Log (development)'; }

    public static function type(): GatewayType { return GatewayType::Sms; }

    public static function description(): string { return 'Writes messages to the application log instead of sending them.'; }

    public static function configSchema(): array { return []; }

    public function send(string $to, string $message): void
    {
        Log::info("SMS to {$to}: {$message}");
    }

    public function test(array $context = []): array
    {
        $this->send($context['to'] ?? '+10000000000', 'Bentorm test message');

        return ['ok' => true, 'message' => 'Written to storage/logs/laravel.log'];
    }
}
