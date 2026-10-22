<?php

namespace App\Services;

use App\Enums\PaymentMethod as PayMethod;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Events\TripUpdated;
use App\Models\Gateway;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Trip;
use App\Models\User;
use App\Plugins\CardSetupResult;
use App\Plugins\Contracts\PaymentPlugin;
use App\Plugins\PaymentResult;
use App\Plugins\PluginManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Everything card-related:
 *   saved cards      startCardSetup / completeCardSetup / setDefault / remove
 *   ride holds       placeHold (at request) → settleTrip (capture at end, top-up any overrun)
 *                    chargeCancellationFee (capture from the hold) / releaseHold (void)
 *   amounts owed     start (pay with a saved card or a hosted page) / waive
 *   gateway events   handleReturn (browser back from hosted page or 3-D Secure) / handleWebhook
 *
 * Gateway calls are made OUTSIDE database transactions; only the bookkeeping is transactional.
 */
class PaymentService
{
    public function __construct(private readonly PluginManager $plugins) {}

    // ================================================================= saved cards

    public function startCardSetup(User $user, ?int $serviceAreaId): PaymentMethod
    {
        $gateway = $this->plugins->paymentGatewayFor($serviceAreaId);
        if (! $gateway) {
            throw ValidationException::withMessages(['card' => ['Card payments are not available here yet.']]);
        }
        $plugin = $this->plugins->payment($gateway);
        if (! $plugin::supportsSavedCards()) {
            throw ValidationException::withMessages(['card' => ['This payment gateway does not support saved cards.']]);
        }

        $pm = PaymentMethod::query()->create(['user_id' => $user->id, 'gateway_id' => $gateway->id, 'status' => 'pending']);

        try {
            $result = $plugin->startCardSetup($pm, [
                'return_url' => route('payment-methods.return', ['paymentMethod' => $pm->public_id]),
                'customer' => $this->customer($user),
            ]);
        } catch (\Throwable $e) {
            Log::error('Card setup failed to start', ['pm' => $pm->id, 'error' => $e->getMessage()]);
            $result = CardSetupResult::failed('The payment gateway could not be reached. Please try again.');
        }

        return $this->applySetup($pm, $result);
    }

    public function completeCardSetup(PaymentMethod $pm, Request $request): PaymentMethod
    {
        if ($pm->status !== 'pending') {
            return $pm;
        }

        return $this->applySetup($pm, $this->plugins->payment($pm->gateway)->completeCardSetup($pm, $request));
    }

    public function setDefault(PaymentMethod $pm): PaymentMethod
    {
        abort_unless($pm->status === 'active', 409, 'Only active cards can be the default.');
        DB::transaction(function () use ($pm) {
            PaymentMethod::query()->where('user_id', $pm->user_id)->where('id', '!=', $pm->id)->update(['is_default' => false]);
            $pm->forceFill(['is_default' => true])->save();
        });

        return $pm->fresh();
    }

    public function remove(PaymentMethod $pm): void
    {
        $inUse = Trip::query()->where('payment_method_id', $pm->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, TripStatus::active()))->exists();
        abort_if($inUse, 409, 'This card is being used for a ride in progress.');

        $pm->forceFill(['status' => 'removed', 'is_default' => false, 'token' => null])->save();
    }

    private function applySetup(PaymentMethod $pm, CardSetupResult $r): PaymentMethod
    {
        $fill = match ($r->status) {
            CardSetupResult::REDIRECT => ['redirect_url' => $r->redirectUrl, 'setup_ref' => $r->setupRef],
            CardSetupResult::ACTIVE => [
                'status' => 'active', 'token' => $r->token, 'brand' => $r->brand, 'last4' => $r->last4,
                'exp_month' => $r->expMonth, 'exp_year' => $r->expYear, 'holder_name' => $r->holderName, 'redirect_url' => null,
            ],
            CardSetupResult::FAILED => ['status' => 'failed', 'failure_reason' => mb_substr((string) $r->message, 0, 255), 'redirect_url' => null],
            default => [],
        };
        $pm->forceFill($fill)->save();

        // First working card becomes the default.
        if ($pm->status === 'active' && ! PaymentMethod::query()->where('user_id', $pm->user_id)->where('is_default', true)->where('status', 'active')->exists()) {
            $pm->forceFill(['is_default' => true])->save();
        }

        return $pm->fresh();
    }

    // ================================================================= ride holds

    /** Resolve which saved card pays for a ride, and check it works in the trip's area. */
    public function cardForRide(User $rider, ?string $paymentMethodPublicId, int $serviceAreaId): PaymentMethod
    {
        $pm = $paymentMethodPublicId
            ? $rider->paymentMethods()->where('public_id', $paymentMethodPublicId)->first()
            : $rider->defaultCard();

        if (! $pm || ! $pm->isUsable()) {
            throw ValidationException::withMessages(['payment_method_id' => [$pm?->isExpired() ? 'This card has expired. Please add a new card.' : 'Add a card to pay by card.']]);
        }
        $gateway = $this->plugins->paymentGatewayFor($serviceAreaId);
        if (! $gateway) {
            throw ValidationException::withMessages(['payment_method' => ['Card payments are not available here yet. Please choose cash.']]);
        }
        if ($pm->gateway_id !== $gateway->id) {
            throw ValidationException::withMessages(['payment_method_id' => ['This card cannot be used in this area. Please add a card here.']]);
        }

        return $pm;
    }

    /**
     * Hold trip.hold_amount on the card. On success the trip moves pending_payment → requested and dispatch starts
     * (see onHoldAuthorized). On 3-D Secure the trip waits for the rider; on decline it is cancelled.
     */
    public function placeHold(Trip $trip, PaymentMethod $pm): Payment
    {
        $hold = Payment::query()->create([
            'trip_id' => $trip->id, 'user_id' => $trip->rider_id, 'gateway_id' => $pm->gateway_id, 'payment_method_id' => $pm->id,
            'purpose' => 'hold', 'method' => PayMethod::Card->value, 'amount' => $trip->hold_amount, 'currency' => $trip->currency, 'status' => 'pending',
        ]);

        $result = $this->call($hold->gateway, fn (PaymentPlugin $p) => $p->authorize($hold, $pm, [
            'return_url' => route('payments.return', ['payment' => $hold->public_id]),
            'webhook_url' => route('payments.webhook', ['gateway' => $hold->gateway_id]),
            'description' => "Ride Coop ride {$trip->public_id} (hold)",
            'customer' => $this->customer($trip->rider),
        ]));

        return $this->apply($hold, $result);
    }

    /**
     * Collect the final fare: capture from the hold (releasing the rest), then charge any overrun to the saved card.
     * Whatever can't be collected becomes amount_due.
     */
    public function settleTrip(Trip $trip): Trip
    {
        $final = (float) $trip->final_fare;
        $hold = $trip->hold();
        $collected = 0.0;

        if ($hold && $hold->status === 'authorized') {
            $capture = round(min($final, (float) $hold->authorized_amount), 2);
            $hold = $this->apply($hold, $this->call($hold->gateway, fn (PaymentPlugin $p) => $p->capture($hold, $capture)));
            if ($hold->status === 'captured') {
                $collected = (float) $hold->captured_amount;
            }
        }

        $remaining = round($final - $collected, 2);
        if ($remaining > 0 && ($card = $trip->card) && $card->isUsable()) {
            $topUp = $this->charge($trip, $card, $remaining, $collected > 0 ? 'top_up' : 'trip_fare');
            if ($topUp->status === 'captured') {
                $collected += (float) $topUp->amount;
            }
        }

        return $this->updateTripBalance($trip, $final, $collected);
    }

    /** Rider cancelled after acceptance: take the fee from the hold, or charge the card, or record it as owed. */
    public function chargeCancellationFee(Trip $trip): Trip
    {
        $fee = (float) $trip->cancellation_fee;
        $hold = $trip->hold();
        $collected = 0.0;

        if ($hold && $hold->status === 'authorized') {
            $hold = $this->apply($hold, $this->call($hold->gateway, fn (PaymentPlugin $p) => $p->capture($hold, min($fee, (float) $hold->authorized_amount))));
            $collected = $hold->status === 'captured' ? (float) $hold->captured_amount : 0.0;
        }
        if ($collected < $fee && $trip->payment_method === PayMethod::Card && ($card = $trip->card) && $card->isUsable()) {
            $charge = $this->charge($trip, $card, round($fee - $collected, 2), 'cancellation_fee');
            $collected += $charge->status === 'captured' ? (float) $charge->amount : 0.0;
        }

        return $this->updateTripBalance($trip, $fee, $collected);
    }

    /** Release any hold (cancelled before acceptance, no drivers, driver/admin cancelled). */
    public function releaseHold(Trip $trip): void
    {
        $hold = $trip->hold();
        if (! $hold || $hold->isFinal()) {
            return;
        }
        if ($hold->status === 'authorized') {
            $this->apply($hold, $this->call($hold->gateway, fn (PaymentPlugin $p) => $p->void($hold)));
        } else {
            // Never authorised (e.g. abandoned 3-D Secure).
            $hold->forceFill(['status' => 'cancelled', 'redirect_url' => null])->save();
        }
    }

    // ================================================================= amounts owed

    /** Pay what's owed on a trip — with a saved card if given, otherwise via a hosted payment page. */
    public function start(Trip $trip, User $rider, ?string $paymentMethodPublicId = null): Payment
    {
        if ($trip->rider_id !== $rider->id) {
            abort(403, 'This is not your trip.');
        }
        if ($trip->payment_status !== PaymentStatus::Pending || $trip->amount_due <= 0) {
            throw ValidationException::withMessages(['trip' => ['Nothing is owed on this trip.']]);
        }
        $purpose = $trip->status === TripStatus::Cancelled ? 'cancellation_fee' : 'trip_fare';

        if ($paymentMethodPublicId) {
            $card = $this->cardForRide($rider, $paymentMethodPublicId, $trip->service_area_id);
            $payment = $this->charge($trip, $card, (float) $trip->amount_due, $purpose);
            $this->updateTripBalance($trip, (float) $trip->amount_due, $payment->status === 'captured' ? (float) $payment->amount : 0.0, true);

            return $payment;
        }

        $gateway = $this->plugins->paymentGatewayFor($trip->service_area_id);
        if (! $gateway) {
            throw ValidationException::withMessages(['payment' => ['Card payments are not available in this area yet.']]);
        }

        $existing = Payment::query()->where('trip_id', $trip->id)->where('status', 'pending')->where('purpose', $purpose)
            ->where('gateway_id', $gateway->id)->where('amount', $trip->amount_due)
            ->where('created_at', '>', now()->subMinutes(25))->whereNotNull('redirect_url')->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $payment = Payment::query()->create([
            'trip_id' => $trip->id, 'user_id' => $rider->id, 'gateway_id' => $gateway->id, 'purpose' => $purpose,
            'method' => PayMethod::Card->value, 'amount' => $trip->amount_due, 'currency' => $trip->currency, 'status' => 'pending',
        ]);

        return $this->apply($payment, $this->call($gateway, fn (PaymentPlugin $p) => $p->initiate($payment, [
            'return_url' => route('payments.return', ['payment' => $payment->public_id]),
            'webhook_url' => route('payments.webhook', ['gateway' => $gateway->id]),
            'description' => "Ride Coop trip {$trip->public_id}",
            'customer' => $this->customer($rider),
        ])));
    }

    public function waive(Trip $trip, User $admin, string $reason): Trip
    {
        $trip->forceFill(['payment_status' => PaymentStatus::Waived, 'amount_due' => 0])->save();
        Log::info('Amount waived', ['trip' => $trip->public_id, 'admin' => $admin->id, 'reason' => $reason]);
        event(new TripUpdated($trip));

        return $trip;
    }

    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        $captured = (float) ($payment->captured_amount ?? $payment->amount);
        if ($payment->status !== 'captured' || ! $payment->gateway) {
            throw ValidationException::withMessages(['payment' => ['Only captured card payments can be refunded.']]);
        }
        $plugin = $this->plugins->payment($payment->gateway);
        if (! $plugin::supportsRefunds()) {
            throw ValidationException::withMessages(['payment' => ['This gateway does not support refunds from Ride Coop. Refund in the gateway portal.']]);
        }

        return $this->apply($payment, $plugin->refund($payment, $amount ?? $captured));
    }

    // ================================================================= gateway callbacks

    public function handleReturn(Payment $payment, Request $request): Payment
    {
        if ($payment->isFinal() || $payment->status === 'authorized') {
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

    /**
     * Record a gateway outcome on a payment and apply its effect on the trip. Idempotent.
     */
    public function apply(Payment $payment, PaymentResult $result): Payment
    {
        $effect = null;

        $p = DB::transaction(function () use ($payment, $result, &$effect) {
            /** @var Payment $p */
            $p = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($p->isFinal() && $result->status !== PaymentResult::REFUNDED) {
                return $p;
            }
            if ($p->status === 'authorized' && $result->status === PaymentResult::AUTHORIZED) {
                return $p; // duplicate (return + webhook)
            }

            $p->gateway_ref = $result->gatewayRef ?? $p->gateway_ref;
            $p->payload = array_merge($p->payload ?? [], array_filter(['last' => $result->raw ?: null, 'message' => $result->message]));

            switch ($result->status) {
                case PaymentResult::AUTHORIZED:
                    $p->status = 'authorized';
                    $p->authorized_amount = $result->amount ?? $p->amount;
                    $p->redirect_url = null;
                    $effect = $p->isHold() ? 'hold_authorized' : null;
                    break;
                case PaymentResult::CAPTURED:
                    $p->status = 'captured';
                    $p->captured_amount = $result->amount ?? $p->amount;
                    $p->paid_at = now();
                    $p->redirect_url = null;
                    // Holds are settled by settleTrip/chargeCancellationFee; direct charges via hosted pages settle here.
                    $effect = ! $p->isHold() ? 'charge_captured' : null;
                    break;
                case PaymentResult::VOIDED:
                    $p->status = 'voided';
                    $p->redirect_url = null;
                    break;
                case PaymentResult::REDIRECT:
                    $p->redirect_url = $result->redirectUrl;
                    break;
                case PaymentResult::FAILED:
                    $p->status = 'failed';
                    $p->failure_reason = mb_substr((string) $result->message, 0, 255);
                    $p->redirect_url = null;
                    $effect = $p->isHold() ? 'hold_failed' : null;
                    break;
                case PaymentResult::REFUNDED:
                    $p->status = 'refunded';
                    Trip::query()->whereKey($p->trip_id)->update(['payment_status' => PaymentStatus::Refunded->value]);
                    break;
            }
            $p->save();

            return $p;
        });

        match ($effect) {
            'hold_authorized' => $this->onHoldAuthorized($p),
            'hold_failed' => $this->onHoldFailed($p),
            'charge_captured' => $this->onHostedChargeCaptured($p),
            default => null,
        };

        return $p;
    }

    // ================================================================= internals

    private function onHoldAuthorized(Payment $hold): void
    {
        $trip = DB::transaction(function () use ($hold) {
            $t = Trip::query()->whereKey($hold->trip_id)->lockForUpdate()->first();
            if (! $t || $t->status !== TripStatus::PendingPayment) {
                return null;
            }
            $t->forceFill(['status' => TripStatus::Requested, 'requested_at' => now()])->save();

            return $t;
        });

        if ($trip) {
            app(DispatchService::class)->start($trip);
            event(new TripUpdated($trip));
        } elseif (($t = Trip::query()->find($hold->trip_id)) && $t->status->isTerminal()) {
            // Authorised after the trip was cancelled (e.g. late 3-D Secure) — release it.
            $this->apply($hold, $this->call($hold->gateway, fn (PaymentPlugin $p) => $p->void($hold)));
        }
    }

    private function onHoldFailed(Payment $hold): void
    {
        $trip = Trip::query()->find($hold->trip_id);
        if ($trip && $trip->status === TripStatus::PendingPayment) {
            $trip->forceFill([
                'status' => TripStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => 'system',
                'cancellation_reason' => 'Card authorisation failed: '.($hold->failure_reason ?: 'declined'),
                'payment_status' => PaymentStatus::Failed,
            ])->save();
            event(new TripUpdated($trip));
        }
    }

    private function onHostedChargeCaptured(Payment $p): void
    {
        $trip = Trip::query()->find($p->trip_id);
        if ($trip && $trip->amount_due > 0) {
            $this->updateTripBalance($trip, (float) $trip->amount_due, (float) $p->captured_amount, true);
        }
    }

    private function charge(Trip $trip, PaymentMethod $card, float $amount, string $purpose): Payment
    {
        $payment = Payment::query()->create([
            'trip_id' => $trip->id, 'user_id' => $trip->rider_id, 'gateway_id' => $card->gateway_id, 'payment_method_id' => $card->id,
            'purpose' => $purpose, 'method' => PayMethod::Card->value, 'amount' => round($amount, 2), 'currency' => $trip->currency, 'status' => 'pending',
        ]);
        // Bookkeeping for direct charges is done by the caller (updateTripBalance), so apply without trip effect.
        $result = $this->call($payment->gateway, fn (PaymentPlugin $p) => $p->charge($payment, $card, [
            'description' => "Ride Coop ride {$trip->public_id}", 'customer' => $this->customer($trip->rider),
        ]));

        return $this->record($payment, $result);
    }

    /** Like apply() but without trip side effects — for charges whose bookkeeping the caller handles. */
    private function record(Payment $payment, PaymentResult $result): Payment
    {
        $payment->gateway_ref = $result->gatewayRef ?? $payment->gateway_ref;
        $payment->payload = array_filter(['last' => $result->raw ?: null, 'message' => $result->message]);
        if ($result->status === PaymentResult::CAPTURED) {
            $payment->status = 'captured';
            $payment->captured_amount = $result->amount ?? $payment->amount;
            $payment->paid_at = now();
        } elseif ($result->status === PaymentResult::FAILED) {
            $payment->status = 'failed';
            $payment->failure_reason = mb_substr((string) $result->message, 0, 255);
        } elseif ($result->status === PaymentResult::REDIRECT) {
            // A merchant-initiated charge asked for the rider (unusual) — leave it owed; the rider can pay from the app.
            $payment->status = 'cancelled';
            $payment->failure_reason = 'Card requires the rider to confirm.';
        }
        $payment->save();

        return $payment;
    }

    /** $due = what should have been collected now; $collected = what was. */
    private function updateTripBalance(Trip $trip, float $due, float $collected, bool $reducingExisting = false): Trip
    {
        $remaining = max(0, round($due - $collected, 2));
        $trip->forceFill([
            'amount_due' => $remaining,
            'payment_status' => $remaining > 0 ? PaymentStatus::Pending : PaymentStatus::Paid,
        ])->save();
        event(new TripUpdated($trip));

        return $trip;
    }

    /** Run a gateway call; network/plugin exceptions become a FAILED result instead of a 500. */
    private function call(?Gateway $gateway, callable $fn): PaymentResult
    {
        if (! $gateway) {
            return PaymentResult::failed('Payment gateway not configured.');
        }
        try {
            return $fn($this->plugins->payment($gateway));
        } catch (\Throwable $e) {
            Log::error('Payment gateway error', ['gateway' => $gateway->id, 'error' => $e->getMessage()]);

            return PaymentResult::failed('The payment gateway could not be reached.');
        }
    }

    private function customer(?User $u): array
    {
        return ['id' => $u?->id, 'name' => $u?->name, 'phone' => $u?->phone, 'email' => $u?->email];
    }
}
