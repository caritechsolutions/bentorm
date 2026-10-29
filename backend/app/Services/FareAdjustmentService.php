<?php

namespace App\Services;

use App\Enums\TripStatus;
use App\Models\CreditEntry;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Support\PushMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change a finished ride's fare (a dispute, a wrong route, a forgotten toll). The driver's (or fleet's) share and
 * Ride Coop's commission keep their proportions; the difference is booked on the driver's / fleet's statement.
 * Lower: the rider gets the difference back — on their card, as ride credit, or in cash from the office.
 * Higher: the rider owes the difference (paid like any amount owed, before their next booking).
 */
class FareAdjustmentService
{
    public function __construct(private readonly PaymentService $payments, private readonly LedgerService $ledger, private readonly PushService $push) {}

    /** @param string $refundAs card | credit | cash (ignored when the fare goes up) */
    public function adjust(Trip $trip, float $newFare, string $reason, string $refundAs, User $admin): Trip
    {
        if ($trip->status !== TripStatus::Completed || $trip->isDelivery()) {
            throw ValidationException::withMessages(['new_fare' => 'Only finished rides can be adjusted.']);
        }
        $old = (float) $trip->final_fare;
        $newFare = round($newFare, 2);
        if ($newFare < 0 || abs($newFare - $old) < 0.01) {
            throw ValidationException::withMessages(['new_fare' => 'Enter a different fare.']);
        }
        $delta = round($newFare - $old, 2); // − = rider gets money back
        $cardPayment = Payment::query()->where('trip_id', $trip->id)->where('status', 'captured')->latest('id')->first();
        if ($delta < 0 && $refundAs === 'card' && ! $cardPayment) {
            throw ValidationException::withMessages(['refund_as' => 'This ride has no card payment to refund. Choose ride credit or cash.']);
        }
        $refund = $delta < 0 ? min(abs($delta), $trip->payable()) : 0.0; // promo/credit-covered part isn't refunded in money

        return DB::transaction(function () use ($trip, $old, $newFare, $delta, $reason, $refundAs, $admin, $cardPayment, $refund) {
            $ratio = $old > 0 ? $newFare / $old : 0;
            $oldEarnings = (float) $trip->driver_earnings;
            $newEarnings = round($oldEarnings * $ratio, 2);
            $breakdown = $trip->fare_breakdown ?? [];
            $breakdown['adjustment'] = ['from' => $old, 'to' => $newFare, 'reason' => $reason, 'at' => now()->toIso8601String()];
            $trip->forceFill([
                'final_fare' => $newFare,
                'driver_earnings' => $newEarnings,
                'platform_fee' => round((float) $trip->platform_fee * $ratio, 2),
                'subtotal' => $trip->subtotal !== null ? round((float) $trip->subtotal * $ratio, 2) : null,
                'tax_amount' => $trip->tax_amount !== null ? round((float) $trip->tax_amount * $ratio, 2) : null,
                'fare_breakdown' => $breakdown,
            ])->save();

            // The driver's / fleet's statement: their share moves with the fare (card or cash alike — on a cash ride
            // they hold the original cash, and Ride Coop refunds the rider).
            [$type, $id] = $this->ledger->payee($trip);
            if (abs($newEarnings - $oldEarnings) >= 0.01) {
                LedgerEntry::query()->create(['payee_type' => $type, 'payee_id' => $id, 'kind' => 'adjustment', 'amount' => round($newEarnings - $oldEarnings, 2),
                    'currency' => $trip->currency ?: 'BBD', 'trip_id' => $trip->id,
                    'description' => sprintf('Fare corrected %s: %.2f → %.2f (%s)', $trip->requested_at?->format('j M') ?? '', $old, $newFare, mb_substr($reason, 0, 80))]);
            }

            $how = 'none';
            if ($delta < 0 && $refund > 0) {
                $how = $refundAs;
                if ($refundAs === 'card') {
                    $this->payments->refund($cardPayment, $refund);
                    if ($refund < (float) ($cardPayment->captured_amount ?? $cardPayment->amount) - 0.009) {
                        Trip::query()->whereKey($trip->id)->update(['payment_status' => 'paid']); // part refunded: the ride is still paid
                    }
                } elseif ($refundAs === 'credit') {
                    CreditEntry::query()->create(['user_id' => $trip->rider_id, 'amount' => $refund, 'currency' => $trip->currency, 'reason' => 'refund',
                        'trip_id' => $trip->id, 'note' => 'Fare corrected: '.mb_substr($reason, 0, 100)]);
                }
                // cash: the office hands it over; recorded here only.
            } elseif ($delta > 0) {
                $how = 'owed';
                $trip->forceFill(['amount_due' => round((float) $trip->amount_due + $delta, 2), 'payment_status' => 'pending'])->save();
            }

            DB::table('fare_adjustments')->insert(['trip_id' => $trip->id, 'old_fare' => $old, 'new_fare' => $newFare, 'old_earnings' => $oldEarnings,
                'new_earnings' => $newEarnings, 'refund_as' => $how, 'refund_amount' => $refund, 'reason' => mb_substr($reason, 0, 255),
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);

            $cur = $trip->currency ?: 'BBD';
            $body = $delta < 0
                ? sprintf('We corrected your ride fare from %s %.2f to %s %.2f. %s', $cur, $old, $cur, $newFare, match ($how) {
                    'card' => sprintf('%s %.2f is going back to your card.', $cur, $refund),
                    'credit' => sprintf('%s %.2f has been added to your ride credit.', $cur, $refund),
                    'cash' => sprintf('Collect %s %.2f from the Ride Coop office.', $cur, $refund),
                    default => '',
                })
                : sprintf('We corrected your ride fare from %s %.2f to %s %.2f. The %s %.2f difference is due in the app.', $cur, $old, $cur, $newFare, $cur, $delta);
            $this->push->notify($trip->rider_id, new PushMessage('Your fare was corrected', trim($body), ['type' => 'trip', 'trip_id' => $trip->public_id]));

            return $trip->fresh();
        });
    }
}
