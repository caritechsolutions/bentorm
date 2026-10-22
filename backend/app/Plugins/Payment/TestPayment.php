<?php

namespace App\Plugins\Payment;

use App\Enums\GatewayType;
use App\Models\Payment;
use App\Plugins\AbstractPlugin;
use App\Plugins\Contracts\PaymentPlugin;
use App\Plugins\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Fake card gateway for development and app testing. "hosted_page" mode shows a page with
 * Approve / Decline buttons, exercising the same redirect + return flow a real gateway uses.
 */
class TestPayment extends AbstractPlugin implements PaymentPlugin
{
    public static function key(): string { return 'test_payment'; }

    public static function name(): string { return 'Test card gateway'; }

    public static function type(): GatewayType { return GatewayType::Payment; }

    public static function description(): string { return 'Simulated card payments for testing. Never use in production.'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'mode', 'label' => 'Behaviour', 'type' => 'select', 'options' => ['hosted_page', 'auto_approve', 'auto_decline'], 'default' => 'hosted_page'],
        ];
    }

    public static function supportsRefunds(): bool { return true; }

    public function initiate(Payment $payment, array $context): PaymentResult
    {
        $ref = 'test_'.Str::lower(Str::random(16));

        return match ($this->cfg('mode')) {
            'auto_approve' => PaymentResult::captured($ref),
            'auto_decline' => PaymentResult::failed('Card declined (test gateway).', $ref),
            default => PaymentResult::redirect(
                URL::temporarySignedRoute('payments.test.checkout', now()->addMinutes(30), ['payment' => $payment->public_id]),
                $ref,
            ),
        };
    }

    public function verifyReturn(Payment $payment, Request $request): PaymentResult
    {
        // The checkout page records the outcome before redirecting back; trust our own record.
        return match ($payment->status) {
            'captured' => PaymentResult::captured($payment->gateway_ref),
            'failed' => PaymentResult::failed($payment->failure_reason ?? 'Declined', $payment->gateway_ref),
            default => PaymentResult::pending($payment->gateway_ref),
        };
    }

    public function handleWebhook(Request $request): ?PaymentResult
    {
        return null;
    }

    public function refund(Payment $payment, float $amount): PaymentResult
    {
        return new PaymentResult(PaymentResult::REFUNDED, $payment->gateway_ref);
    }

    public function test(array $context = []): array
    {
        return ['ok' => true, 'message' => 'Test gateway is always available.'];
    }
}
