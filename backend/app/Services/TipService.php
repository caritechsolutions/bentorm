<?php

namespace App\Services;

use App\Enums\TripStatus;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Support\Features;
use App\Support\PushMessage;
use Illuminate\Validation\ValidationException;

/** Tips after a ride or delivery: charged to a saved card, 100% to the driver, once per trip. */
class TipService
{
    public function __construct(private readonly PaymentService $payments, private readonly PushService $push) {}

    /** Can this person still add a tip to this finished trip? */
    public function canTip(Trip $trip, ?User $user): bool
    {
        return $user !== null && $trip->rider_id === $user->id && $trip->driver_id !== null && Features::on('tips')
            && $trip->status === TripStatus::Completed && $trip->tipped_at === null
            && $trip->completed_at?->gt(now()->subHours((int) Features::number('tips.window_hours')));
    }

    public function tip(Trip $trip, User $user, float $amount, ?string $cardId = null): Trip
    {
        Features::require('tips', 'Tipping');
        abort_unless($trip->rider_id === $user->id, 404);
        if (! $this->canTip($trip, $user)) {
            throw ValidationException::withMessages(['amount' => [$trip->tipped_at ? 'You already tipped for this trip. Thank you!' : 'This trip can no longer be tipped.']]);
        }
        $max = Features::number('tips.max_amount');
        $amount = round($amount, 2);
        if ($amount < 1 || $amount > $max) {
            throw ValidationException::withMessages(['amount' => [sprintf('Choose a tip between 1.00 and %.2f.', $max)]]);
        }
        $card = $this->payments->cardForRide($user, $cardId ?? ($trip->order?->card?->public_id ?? $trip->card?->public_id), $trip->service_area_id);
        $payment = $this->payments->chargeTip($trip, $card, $amount);
        if ($payment->status !== 'captured') {
            abort(402, 'Your card was declined for the tip: '.($payment->failure_reason ?: 'try another card').'.');
        }
        $trip->forceFill(['tip_amount' => round((float) $trip->tip_amount + $amount, 2), 'tipped_at' => now()])->save();
        app(LedgerService::class)->bookTip($trip, $amount);
        if ($trip->order_id) {
            Order::query()->whereKey($trip->order_id)->update(['tip' => round((float) $trip->order->tip + $amount, 2)]);
        }
        $this->push->notify($trip->driver_id, new PushMessage('You got a tip!',
            sprintf('%s tipped you %s %.2f. Thank you for a great %s.', $user->firstName(), $trip->currency, $amount, $trip->order_id ? 'delivery' : 'ride'),
            ['type' => 'tip', 'trip_id' => $trip->public_id]));

        return $trip->fresh();
    }
}
