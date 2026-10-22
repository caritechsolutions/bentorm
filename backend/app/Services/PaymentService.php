<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Events\TripUpdated;
use App\Models\Gateway;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Plugins\PaymentResult;
use App\Plugins\PluginManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Card collection for amounts owed on a trip (fare or cancellation fee) through the area's payment gateway.
 */
class PaymentService
{
    public function __construct(private readonly PluginManager $plugins) {}

    /** Start (or resume) a card payment for what's owed on a trip. */
    public function start(Trip $trip, User $rider): Payment
    {
        if ($trip->rider_id !== $rider->id) {
            abort(403, 'This is not your trip.');
        }
        if ($trip->payment_status !== PaymentStatus::Pending || $trip->amount_due <= 0) {
            throw ValidationException::withMessages(['trip' => ['Nothing is owed on this trip.']]);
        }

        $gateway = $this->plugins->paymentGatewayFor($trip->service_area_id);
        if (! $gateway) {
            throw ValidationException::withMessages(['payment' => ['Card payments are not available in this area yet.']]);
        }

        // Resume a recent redirect instead of creating duplicates.
        $existing = Payment::query()->where('trip_id', $trip->id)->where('status', 'pending')
            ->where('gateway_id', $gateway->id)->where('amount', $trip->amount_due)
            ->where('created_at', '>', now()->subMinutes(25))->latest('id')->first();
        if ($existing?->redirect_url) {
            return $existing;
        }

        $payment = Payment::query()->create([
            'trip_id' => $trip->id,
            'user_id' => $rider->id,
            'gateway_id' => $gateway->id,
            'purpose' => $trip->status === TripStatus::Cancelled ? 'cancellation_fee' : 'trip_fare',
            'method' => PaymentMethod::Card->value,
            'amount' => $trip->amount_due,
            'currency' => $trip->currency,
            'status' => 'pending',
        ]);

        try {
            $result = $this->plugins->payment($gateway)->initiate($payment, [
                'return_url' => route('payments.return', ['payment' => $payment->public_id]),
                'webhook_url' => route('payments.webhook', ['gateway' => $gateway->id]),
                'description' => "RideCoop trip {$trip->public_id}",
                'customer' => ['name' => $rider->name, 'phone' => $rider->phone, 'email' => $rider->email],
            ]);
        } catch (\Throwable $e) {
            Log::error('Payment initiate failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
            $result = PaymentResult::failed('The payment gateway could not be reached. Please try again.');
        }

        return $this->apply($payment, $result);
    }

    public function handleReturn(Payment $payment, Request $request): Payment
    {
        if ($payment->isFinal()) {
            return $payment;
        }

        return $this->apply($payment, $this->plugins->payment($payment->gateway)->verifyReturn($payment, $request));
    }

    public function handleWebhook(Gateway $gateway, Request $request): ?Payment
    {
        $result = $this->plugins->payment($gateway)->handleWebhook($request);
        if (! $result || ! $result->gatewayRef) {
            return null;
        }
        $payment = Payment::query()->where('gateway_id', $gateway->id)->where('gateway_ref', $result->gatewayRef)->first();

        return $payment ? $this->apply($payment, $result) : null;
    }

    /** Record a gateway outcome on the payment and, when captured, settle the trip. Idempotent. */
    public function apply(Payment $payment, PaymentResult $result): Payment
    {
        return DB::transaction(function () use ($payment, $result) {
            /** @var Payment $p */
            $p = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($p->isFinal() && $result->status !== PaymentResult::REFUNDED) {
                return $p;
            }

            $p->gateway_ref = $result->gatewayRef ?? $p->gateway_ref;
            $p->payload = array_merge($p->payload ?? [], array_filter(['last' => $result->raw ?: null, 'message' => $result->message]));

            switch ($result->status) {
                case PaymentResult::CAPTURED:
                    $p->status = 'captured';
                    $p->paid_at = now();
                    $p->redirect_url = null;
                    $trip = Trip::query()->whereKey($p->trip_id)->lockForUpdate()->first();
                    if ($trip) {
                        $trip->forceFill(['payment_status' => PaymentStatus::Paid, 'amount_due' => 0])->save();
                        DB::afterCommit(fn () => event(new TripUpdated($trip)));
                    }
                    break;
                case PaymentResult::REDIRECT:
                    $p->redirect_url = $result->redirectUrl;
                    break;
                case PaymentResult::FAILED:
                    $p->status = 'failed';
                    $p->failure_reason = mb_substr((string) $result->message, 0, 255);
                    $p->redirect_url = null;
                    break;
                case PaymentResult::REFUNDED:
                    $p->status = 'refunded';
                    Trip::query()->whereKey($p->trip_id)->update(['payment_status' => PaymentStatus::Refunded->value]);
                    break;
            }
            $p->save();

            return $p;
        });
    }

    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        if ($payment->status !== 'captured' || ! $payment->gateway) {
            throw ValidationException::withMessages(['payment' => ['Only captured card payments can be refunded.']]);
        }
        $plugin = $this->plugins->payment($payment->gateway);
        if (! $plugin::supportsRefunds()) {
            throw ValidationException::withMessages(['payment' => ['This gateway does not support refunds from RideCoop. Refund in the gateway portal.']]);
        }

        return $this->apply($payment, $plugin->refund($payment, $amount ?? $payment->amount));
    }

    /** Admin waives an amount owed (e.g. a disputed cancellation fee). */
    public function waive(Trip $trip, User $admin, string $reason): Trip
    {
        $trip->forceFill(['payment_status' => PaymentStatus::Waived, 'amount_due' => 0])->save();
        Log::info('Amount waived', ['trip' => $trip->public_id, 'admin' => $admin->id, 'reason' => $reason]);
        event(new TripUpdated($trip));

        return $trip;
    }
}
