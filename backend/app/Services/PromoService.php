<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\CreditEntry;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Trip;
use App\Models\User;
use App\Support\Features;
use Illuminate\Validation\ValidationException;

/**
 * Promo codes and ride credit on rides and shop orders. Codes are checked when booking / at checkout and reserved;
 * the discount is worked out on the final price (rides: at the end of the trip) and marked used. Cancelled → released
 * and any credit returned. Ride Coop pays discounts and credit: drivers and stores are paid on the full price.
 */
class PromoService
{
    /** A code this person can use now for $context (rides|shop), spending about $spend in area $areaId. */
    public function check(string $code, ?User $user, string $context, float $spend, ?int $areaId): PromoCode
    {
        Features::require('promos', 'Promo codes');
        $fail = fn (string $m) => throw ValidationException::withMessages(['promo_code' => [$m]]);
        $p = PromoCode::query()->where('code', strtoupper(trim($code)))->first();
        if (! $p || ! $p->is_active || ($p->starts_at && $p->starts_at->isFuture())) {
            $fail('That promo code isn\'t valid.');
        }
        if ($p->ends_at && $p->ends_at->isPast()) {
            $fail('That promo code has expired.');
        }
        if (! in_array($p->applies_to, ['both', $context], true)) {
            $fail($context === 'rides' ? 'That code is for shop orders.' : 'That code is for rides.');
        }
        if ($p->service_area_id && $areaId && $p->service_area_id !== $areaId) {
            $fail('That code can\'t be used here.');
        }
        if ($spend < $p->min_spend) {
            $fail(sprintf('Spend at least %.2f to use this code.', $p->min_spend));
        }
        $live = fn () => $p->redemptions()->whereIn('status', ['applied', 'used']);
        if ($p->max_uses && $live()->count() >= $p->max_uses) {
            $fail('That promo code has been used up.');
        }
        if ($user) {
            if ($live()->where('user_id', $user->id)->count() >= max(1, $p->max_uses_per_user)) {
                $fail('You\'ve already used this code.');
            }
            if ($p->first_order_only && ! $this->isNewCustomer($user)) {
                $fail('That code is for your first ride or order.');
            }
        }

        return $p;
    }

    public function isNewCustomer(User $user): bool
    {
        return ! Trip::query()->rides()->where('rider_id', $user->id)->where('status', TripStatus::Completed->value)->exists()
            && ! Order::query()->where('customer_id', $user->id)->where('status', OrderStatus::Delivered->value)->exists();
    }

    public function reserve(PromoCode $p, User $user, ?Trip $trip = null, ?Order $order = null, float $amount = 0): PromoRedemption
    {
        return PromoRedemption::query()->create(['promo_code_id' => $p->id, 'user_id' => $user->id, 'trip_id' => $trip?->id,
            'order_id' => $order?->id, 'amount' => $amount, 'status' => 'applied']);
    }

    /**
     * End of a ride: the promo discount on the final fare, then credit (if the rider chose to use it), both marked
     * used. Returns [discount, credit].
     *
     * @return array{0: float, 1: float}
     */
    public function settleRide(Trip $t, float $fare): array
    {
        $discount = 0.0;
        $redemption = $t->promo_code_id ? PromoRedemption::query()->where('trip_id', $t->id)->where('status', 'applied')->first() : null;
        if ($redemption) {
            $discount = $redemption->promoCode->discountOn($fare);
            $redemption->update(['amount' => $discount, 'status' => 'used']);
        }
        $credit = 0.0;
        if ($t->use_credit) {
            $credit = $this->creditFor($t->rider_id, $fare - $discount, 0.0);
            if ($credit > 0) {
                CreditEntry::query()->create(['user_id' => $t->rider_id, 'amount' => -$credit, 'currency' => $t->currency, 'reason' => 'trip', 'trip_id' => $t->id]);
            }
        }

        return [$discount, $credit];
    }

    /** How much credit can go on $amount (up to the share set in App features), leaving at least $keep to pay. */
    public function creditFor(int $userId, float $amount, float $keep = 1.0): float
    {
        $balance = CreditEntry::balance($userId);
        if ($balance <= 0 || $amount <= $keep) {
            return 0.0;
        }

        return round(max(0, min($balance, $amount * Features::number('credit.max_share'), $amount - $keep)), 2);
    }

    /** Ride cancelled / never happened: the code can be used again. */
    public function releaseTrip(Trip $t): void
    {
        PromoRedemption::query()->where('trip_id', $t->id)->where('status', 'applied')->update(['status' => 'released']);
    }

    /** Order placed: reserve the code and take the credit now (it is part of the card hold's total). */
    public function onOrderPlaced(Order $o, ?PromoCode $p): void
    {
        if ($p) {
            $this->reserve($p, $o->customer, order: $o, amount: (float) $o->discount);
        }
        if ($o->credit > 0) {
            CreditEntry::query()->create(['user_id' => $o->customer_id, 'amount' => -$o->credit, 'currency' => $o->currency, 'reason' => 'order', 'order_id' => $o->id]);
        }
    }

    /** Order done: the code is used. */
    public function onOrderDelivered(Order $o): void
    {
        PromoRedemption::query()->where('order_id', $o->id)->where('status', 'applied')->update(['status' => 'used', 'amount' => $o->discount]);
    }

    /** Order cancelled / declined / expired / card failed: release the code, give the credit back (once). */
    public function onOrderEnded(Order $o): void
    {
        PromoRedemption::query()->where('order_id', $o->id)->where('status', 'applied')->update(['status' => 'released']);
        $used = -(float) CreditEntry::query()->where('order_id', $o->id)->sum('amount');
        if ($used > 0) {
            CreditEntry::query()->create(['user_id' => $o->customer_id, 'amount' => $used, 'currency' => $o->currency, 'reason' => 'refund', 'order_id' => $o->id,
                'note' => 'Order '.$o->number().' did not go ahead']);
        }
    }
}
