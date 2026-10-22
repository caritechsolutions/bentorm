<?php

namespace App\Plugins\Contracts;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Plugins\CardSetupResult;
use App\Plugins\PaymentResult;
use Illuminate\Http\Request;

/**
 * Card payments, Uber-style:
 *
 *   Saved cards   startCardSetup()  → usually a hosted page where the rider enters the card (card data never touches us)
 *                 completeCardSetup() → the browser returns; exchange for a reusable token
 *   Ride request  authorize()  → hold the estimate (+ buffer) on the saved card. May return REDIRECT for 3-D Secure,
 *                               completed via verifyReturn().
 *   Trip ends     capture()    → take the final fare from the hold (partial capture releases the rest)
 *                 charge()     → merchant-initiated charge on the saved card (overrun top-up, cancellation without a hold)
 *   Cancelled     void()       → release the hold
 *   One-off       initiate()   → hosted page payment without a saved card (settling an amount owed)
 *   Async         handleWebhook() → must verify the gateway signature; return null if not actionable
 */
interface PaymentPlugin extends Plugin
{
    public static function supportsSavedCards(): bool;

    public static function supportsRefunds(): bool;

    /** @param array{return_url: string, customer: array} $context */
    public function startCardSetup(PaymentMethod $method, array $context): CardSetupResult;

    public function completeCardSetup(PaymentMethod $method, Request $request): CardSetupResult;

    /** @param array{return_url: string, webhook_url: string, description: string, customer: array} $context */
    public function authorize(Payment $payment, PaymentMethod $method, array $context): PaymentResult;

    public function capture(Payment $payment, float $amount): PaymentResult;

    public function void(Payment $payment): PaymentResult;

    /** @param array{description: string, customer: array} $context */
    public function charge(Payment $payment, PaymentMethod $method, array $context): PaymentResult;

    /** @param array{return_url: string, webhook_url: string, description: string, customer: array} $context */
    public function initiate(Payment $payment, array $context): PaymentResult;

    /** After a redirect (hosted payment or 3-D Secure) — confirm the outcome with the gateway. */
    public function verifyReturn(Payment $payment, Request $request): PaymentResult;

    public function handleWebhook(Request $request): ?PaymentResult;

    public function refund(Payment $payment, float $amount): PaymentResult;
}
