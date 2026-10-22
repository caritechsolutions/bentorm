<?php

namespace App\Plugins\Payment;

use App\Enums\GatewayType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Plugins\AbstractPlugin;
use App\Plugins\CardSetupResult;
use App\Plugins\Contracts\PaymentPlugin;
use App\Plugins\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Simulated gateway for development and app testing — behaves like a real tokenising gateway.
 *
 * Test cards (any future expiry):
 *   4242 4242 4242 4242   approves
 *   4000 0000 0000 0002   saves, then declines every hold/charge
 *   4000 0000 0000 3220   requires 3-D Secure on every hold (approve/decline page)
 *
 * mode = auto_approve skips the hosted pages entirely (saves a 4242 card instantly) — handy for automated tests.
 */
class TestPayment extends AbstractPlugin implements PaymentPlugin
{
    public const CARDS = [
        '4242424242424242' => 'Approves',
        '4000000000000002' => 'Declines holds and charges',
        '4000000000003220' => 'Requires 3-D Secure',
    ];

    public static function key(): string { return 'test_payment'; }

    public static function name(): string { return 'Test card gateway'; }

    public static function type(): GatewayType { return GatewayType::Payment; }

    public static function description(): string { return 'Simulated saved cards, holds and captures for testing. Never use in production.'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'mode', 'label' => 'Behaviour', 'type' => 'select', 'options' => ['hosted_page', 'auto_approve', 'auto_decline'], 'default' => 'hosted_page'],
        ];
    }

    public static function supportsSavedCards(): bool { return true; }

    public static function supportsRefunds(): bool { return true; }

    // ---------------------------------------------------------------- saved cards

    public function startCardSetup(PaymentMethod $method, array $context): CardSetupResult
    {
        if ($this->cfg('mode') === 'auto_approve') {
            return $this->tokenFor('4242424242424242', 12, (int) now()->addYears(3)->format('Y'), $context['customer']['name'] ?? null);
        }

        return CardSetupResult::redirect(
            URL::temporarySignedRoute('payment-methods.test.form', now()->addMinutes(30), ['paymentMethod' => $method->public_id]),
            'setup_'.Str::lower(Str::random(12)),
        );
    }

    public function completeCardSetup(PaymentMethod $method, Request $request): CardSetupResult
    {
        // The test form stored what was entered on the payment method before redirecting back.
        $card = $method->payload['test_card'] ?? null;
        if (! $card) {
            return new CardSetupResult(CardSetupResult::PENDING);
        }
        $number = preg_replace('/\D/', '', $card['number'] ?? '');
        if (! isset(self::CARDS[$number])) {
            return CardSetupResult::failed('Use one of the test card numbers.');
        }

        return $this->tokenFor($number, (int) $card['exp_month'], (int) $card['exp_year'], $card['name'] ?? null);
    }

    // ---------------------------------------------------------------- holds & charges

    public function authorize(Payment $payment, PaymentMethod $method, array $context): PaymentResult
    {
        $ref = 'auth_'.Str::lower(Str::random(16));
        $last4 = $method->last4;

        if ($this->cfg('mode') === 'auto_decline' || $last4 === '0002') {
            return PaymentResult::failed('Card declined (test gateway).', $ref);
        }
        if ($last4 === '3220') {
            return PaymentResult::redirect(
                URL::temporarySignedRoute('payments.test.checkout', now()->addMinutes(30), ['payment' => $payment->public_id]),
                $ref,
            );
        }

        return PaymentResult::authorized($payment->amount, $ref);
    }

    public function capture(Payment $payment, float $amount): PaymentResult
    {
        if ($amount > (float) $payment->authorized_amount + 0.001) {
            return PaymentResult::failed('Capture exceeds the authorised amount.', $payment->gateway_ref);
        }

        return PaymentResult::captured($payment->gateway_ref, [], $amount);
    }

    public function void(Payment $payment): PaymentResult
    {
        return PaymentResult::voided($payment->gateway_ref);
    }

    public function charge(Payment $payment, PaymentMethod $method, array $context): PaymentResult
    {
        $ref = 'ch_'.Str::lower(Str::random(16));
        if ($this->cfg('mode') === 'auto_decline' || $method->last4 === '0002') {
            return PaymentResult::failed('Card declined (test gateway).', $ref);
        }

        return PaymentResult::captured($ref, [], $payment->amount);
    }

    // ---------------------------------------------------------------- one-off hosted payment

    public function initiate(Payment $payment, array $context): PaymentResult
    {
        $ref = 'pay_'.Str::lower(Str::random(16));

        return match ($this->cfg('mode')) {
            'auto_approve' => PaymentResult::captured($ref, [], $payment->amount),
            'auto_decline' => PaymentResult::failed('Card declined (test gateway).', $ref),
            default => PaymentResult::redirect(
                URL::temporarySignedRoute('payments.test.checkout', now()->addMinutes(30), ['payment' => $payment->public_id]),
                $ref,
            ),
        };
    }

    public function verifyReturn(Payment $payment, Request $request): PaymentResult
    {
        // The test checkout page applies the outcome itself; report what we have.
        return match ($payment->status) {
            'authorized' => PaymentResult::authorized((float) $payment->authorized_amount, $payment->gateway_ref),
            'captured' => PaymentResult::captured($payment->gateway_ref, [], (float) $payment->captured_amount),
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
        return PaymentResult::refunded($payment->gateway_ref, $amount);
    }

    public function test(array $context = []): array
    {
        return ['ok' => true, 'message' => 'Test gateway is always available.'];
    }

    private function tokenFor(string $number, int $expMonth, int $expYear, ?string $holder): CardSetupResult
    {
        $brand = str_starts_with($number, '4') ? 'visa' : 'card';

        return CardSetupResult::active('test_tok_'.Str::lower(Str::random(20)), $brand, substr($number, -4), $expMonth, $expYear, $holder);
    }
}
