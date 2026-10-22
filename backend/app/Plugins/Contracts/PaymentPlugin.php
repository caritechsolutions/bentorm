<?php

namespace App\Plugins\Contracts;

use App\Models\Payment;
use App\Plugins\PaymentResult;
use Illuminate\Http\Request;

/**
 * Sale-style card payment. Flow:
 *   initiate()       → usually PaymentResult::redirect(url) to a hosted payment page (keeps card data off our servers)
 *   verifyReturn()   → the rider's browser comes back to our return URL; confirm status with the gateway
 *   handleWebhook()  → server-to-server notification; must verify the gateway's signature
 */
interface PaymentPlugin extends Plugin
{
    /** @param array{return_url: string, webhook_url: string, description: string, customer: array} $context */
    public function initiate(Payment $payment, array $context): PaymentResult;

    public function verifyReturn(Payment $payment, Request $request): PaymentResult;

    /** Returns null when the request is not for us / not actionable. Must set gatewayRef to find the payment. */
    public function handleWebhook(Request $request): ?PaymentResult;

    public function refund(Payment $payment, float $amount): PaymentResult;

    public static function supportsRefunds(): bool;
}
