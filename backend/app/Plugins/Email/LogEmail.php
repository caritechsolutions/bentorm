<?php

namespace App\Plugins\Email;

use App\Enums\GatewayType;
use App\Plugins\AbstractPlugin;
use App\Plugins\Contracts\EmailPlugin;
use Illuminate\Support\Facades\Log;

class LogEmail extends AbstractPlugin implements EmailPlugin
{
    public static function key(): string { return 'log_email'; }

    public static function name(): string { return 'Log (development)'; }

    public static function type(): GatewayType { return GatewayType::Email; }

    public static function description(): string { return 'Writes emails to the application log instead of sending them.'; }

    public static function configSchema(): array { return []; }

    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        Log::info("EMAIL to {$to}: {$subject}\n".($text ?? strip_tags($html)));
    }

    public function test(array $context = []): array
    {
        $this->send($context['to'] ?? 'test@example.com', 'RideCoop test email', '<p>Test</p>');

        return ['ok' => true, 'message' => 'Written to storage/logs/laravel.log'];
    }
}
