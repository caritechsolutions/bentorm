<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\TripStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Books what Ride Coop owes drivers, fleets and stores. + = owed to them, − = they owe (cash rides: the driver kept the
 * fare, so Ride Coop's share is owed back). Every event is booked once, so calling these again is safe.
 */
class LedgerService
{
    /**
     * A finished ride or delivery (and its cancellation fee / checkout tip). Trips in a fleet car earn for the fleet;
     * cash the fleet's driver collected is noted as held for the owner (FleetService::bookCash).
     */
    public function bookTrip(Trip $t): void
    {
        if (! $t->driver_id) {
            return;
        }
        [$type, $id] = $this->payee($t);
        $by = $type === 'fleet' ? $this->drivenBy($t) : '';
        $cur = $t->currency ?: 'BBD';
        $when = $t->requested_at?->format('j M') ?? '';
        if ($t->status === TripStatus::Completed && $t->isDelivery()) {
            $this->once($type, $id, 'delivery', (float) $t->driver_earnings, $cur, "Delivery {$when} (order {$t->order?->number()}){$by}", trip: $t);
            if ($t->tip_amount > 0 && ! $t->tipped_at) {
                [$tt, $ti] = $this->tipPayee($t);
                $this->once($tt, $ti, 'tip', (float) $t->tip_amount, $cur, "Tip at checkout (order {$t->order?->number()})".($tt === 'fleet' ? $by : ''), trip: $t);
            }
        } elseif ($t->status === TripStatus::Completed && $t->payment_method === PaymentMethod::Cash) {
            // The driver kept the cash; promo/credit the rider didn't pay is Ride Coop's to cover.
            $kept = $t->payable();
            $this->once($type, $id, 'ride_cash', round((float) $t->driver_earnings - $kept, 2), $cur,
                sprintf('Cash ride %s: %s collected %.2f, your share %.2f%s', $when, $type === 'fleet' ? 'driver' : 'you', $kept, (float) $t->driver_earnings, $by), trip: $t);
            if ($type === 'fleet') {
                app(FleetService::class)->bookCash($t, $kept);
            }
        } elseif ($t->status === TripStatus::Completed) {
            $this->once($type, $id, 'ride_card', (float) $t->driver_earnings, $cur, "Card ride {$when}{$by}", trip: $t);
        } elseif ($t->status === TripStatus::Cancelled && $t->cancellation_fee > 0 && $t->driver_earnings > 0) {
            $this->once($type, $id, 'cancellation', (float) $t->driver_earnings, $cur, "Cancellation fee {$when}{$by}", trip: $t);
        }
    }

    /** A tip added after the trip (TipService). */
    public function bookTip(Trip $t, float $amount): void
    {
        if ($t->driver_id && $amount > 0) {
            [$type, $id] = $this->tipPayee($t);
            LedgerEntry::query()->create(['payee_type' => $type, 'payee_id' => $id, 'kind' => 'tip', 'amount' => round($amount, 2),
                'currency' => $t->currency ?: 'BBD', 'trip_id' => $t->id, 'description' => 'Tip from '.($t->rider?->firstName() ?? 'a rider').($type === 'fleet' ? $this->drivenBy($t) : '')]);
        }
    }

    /** Who a trip's money goes to: the fleet whose car it was, or the driver. @return array{0: string, 1: int} */
    public function payee(Trip $t): array
    {
        return $t->fleet_id ? ['fleet', (int) $t->fleet_id] : ['driver', (int) $t->driver_id];
    }

    /** Tips go to the driver, unless the fleet whose car it was keeps tips (admin setting per fleet). @return array{0: string, 1: int} */
    public function tipPayee(Trip $t): array
    {
        return $t->fleet_id && $t->fleet && ! $t->fleet->tips_to_driver ? ['fleet', (int) $t->fleet_id] : ['driver', (int) $t->driver_id];
    }

    private function drivenBy(Trip $t): string
    {
        return ' · '.($t->vehicle?->plate ? $t->vehicle->plate.', ' : '').($t->driver?->name ?? 'driver');
    }

    /** A delivered order: the store's share (items + VAT − commission). */
    public function bookOrder(Order $o): void
    {
        $this->once('store', $o->store_id, 'order', (float) $o->store_payout, $o->currency ?: 'BBD', "Order {$o->number()}", order: $o);
    }

    /**
     * Record money sent to (amount > 0) or received from (amount < 0) a driver/store. The ledger gets the opposite
     * entry, so the balance moves towards zero.
     */
    public function recordPayout(string $type, int $id, float $amount, string $method, ?string $reference, ?string $note, ?User $by): Payout
    {
        return DB::transaction(function () use ($type, $id, $amount, $method, $reference, $note, $by) {
            $p = Payout::query()->create(['payee_type' => $type, 'payee_id' => $id, 'amount' => round($amount, 2), 'method' => $method,
                'reference' => $reference, 'note' => $note, 'created_by' => $by?->id]);
            LedgerEntry::query()->create(['payee_type' => $type, 'payee_id' => $id, 'kind' => $amount >= 0 ? 'payout' : 'collection',
                'amount' => -round($amount, 2), 'payout_id' => $p->id,
                'description' => ($amount >= 0 ? 'Paid to you' : 'Received from you').' · '.str_replace('_', ' ', $method).($reference ? " · ref {$reference}" : '')]);

            return $p;
        });
    }

    /**
     * Every driver/store with a balance, biggest first.
     *
     * @return \Illuminate\Support\Collection<int, object{payee_type: string, payee_id: int, balance: float}>
     */
    public function balances(?string $type = null)
    {
        return LedgerEntry::query()->selectRaw('payee_type, payee_id, ROUND(SUM(amount), 2) as balance, MAX(created_at) as last_at')
            ->when($type, fn ($q) => $q->where('payee_type', $type))
            ->groupBy('payee_type', 'payee_id')->havingRaw('ROUND(SUM(amount), 2) <> 0')->orderByRaw('ROUND(SUM(amount), 2) DESC')->get();
    }

    private function once(string $type, int $id, string $kind, float $amount, string $cur, string $desc, ?Trip $trip = null, ?Order $order = null): void
    {
        if (round($amount, 2) == 0.0) {
            return;
        }
        $q = LedgerEntry::query()->where('payee_type', $type)->where('payee_id', $id)->where('kind', $kind);
        $exists = $trip ? (clone $q)->where('trip_id', $trip->id)->exists() : (clone $q)->where('order_id', $order?->id)->exists();
        if (! $exists) {
            LedgerEntry::query()->create(['payee_type' => $type, 'payee_id' => $id, 'kind' => $kind, 'amount' => round($amount, 2), 'currency' => $cur,
                'trip_id' => $trip?->id, 'order_id' => $order?->id, 'description' => mb_substr($desc, 0, 255)]);
        }
    }
}
