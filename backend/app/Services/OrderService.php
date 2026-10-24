<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Filament\Pages\ShopSettings;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\Geo;
use App\Support\PushMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shop orders: price a cart, place it (card hold), and the store's side — accept, decline, remove items with
 * the customer's OK, mark ready (captures the card). Unanswered orders expire and the hold is released.
 *
 * Money: items (+ VAT when the store's prices exclude it) + delivery fee (delivery rate card, incl. VAT)
 * + service fee (incl. VAT) = total. Commission is kept from the items; the store is paid the rest of the items.
 * The delivery fee (ex VAT) is split between Ride Coop (delivery share) and the driver.
 */
class OrderService
{
    public const ACCEPT_MINUTES = 5;

    public function __construct(
        private readonly GeoService $geo,
        private readonly PricingService $pricing,
        private readonly PaymentService $payments,
        private readonly PushService $push,
    ) {}

    // ================================================================= pricing

    /**
     * @param  array<int, array{product_id: int, quantity: int, options?: array, note?: ?string}>  $lines
     * @return array{store: Store, lines: array, totals: array, breakdown: array, route: mixed, dropoff: mixed}
     */
    public function quote(Store $store, array $lines, float $lat, float $lng): array
    {
        if (! $store->isAcceptingOrders()) {
            throw ValidationException::withMessages(['store' => [$store->isApproved() ? "{$store->name} is closed right now." : 'This store is not available.']]);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => ['Your cart is empty.']]);
        }

        // Where it's going: same service area as the store, not blocked, within the store's delivery distance.
        try {
            [$from, $to] = $this->geo->assertTripAllowed((float) $store->lat, (float) $store->lng, $lat, $lng);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['dropoff' => [collect($e->errors())->flatten()->first() ?? 'We can\'t deliver there.']]);
        }
        $straightKm = Geo::haversine((float) $store->lat, (float) $store->lng, $lat, $lng) / 1000;
        if ($straightKm > $store->delivery_radius_km) {
            throw ValidationException::withMessages(['dropoff' => [sprintf('%s delivers up to %s km away. This address is about %.1f km.', $store->name, rtrim(rtrim(number_format($store->delivery_radius_km, 1), '0'), '.'), $straightKm)]]);
        }

        $products = Product::query()->where('store_id', $store->id)->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        $priced = [];
        foreach (array_values($lines) as $i => $line) {
            $p = $products->get((int) ($line['product_id'] ?? 0));
            if (! $p) {
                throw ValidationException::withMessages(["items.{$i}" => ['This item is no longer on the menu.']]);
            }
            if (! $p->is_available) {
                throw ValidationException::withMessages(["items.{$i}" => ["{$p->name} is sold out."]]);
            }
            $qty = (int) ($line['quantity'] ?? 1);
            if ($qty < 1 || $qty > 50) {
                throw ValidationException::withMessages(["items.{$i}" => ['Quantity must be between 1 and 50.']]);
            }
            $chosen = $this->resolveOptions($p, (array) ($line['options'] ?? []), $i);
            $unit = round($p->price + collect($chosen)->sum(fn ($g) => collect($g['choices'])->sum('price')), 2);
            $priced[] = [
                'product_id' => $p->id, 'name' => $p->name, 'quantity' => $qty, 'unit_price' => $unit,
                'line_total' => round($unit * $qty, 2), 'options' => $chosen ?: null, 'size' => $p->size,
                'note' => filled($line['note'] ?? null) ? mb_substr(trim((string) $line['note']), 0, 200) : null,
            ];
        }

        $subtotal = round(array_sum(array_column($priced, 'line_total')), 2);
        if ($subtotal < $store->min_order) {
            throw ValidationException::withMessages(['items' => [sprintf('%s has a minimum order of %s %.2f.', $store->name, $from->service->currency, $store->min_order)]]);
        }

        $delivery = $this->pricing->deliveryQuote($from, $to);

        return ['store' => $store, 'lines' => $priced, 'route' => $delivery['route'], 'dropoff' => $to]
            + $this->totals($store, $subtotal, $delivery);
    }

    /**
     * Validate chosen options against the product: groups must exist, required groups answered, single-choice
     * groups at most one. Prices come from the product, never from the app.
     *
     * @param  array<int, array{group: string, choices: array<int, string>}>  $wanted
     * @return array<int, array{group: string, choices: array<int, array{name: string, price: float}>}>
     */
    private function resolveOptions(Product $p, array $wanted, int $i): array
    {
        $byGroup = collect($wanted)->keyBy(fn ($w) => (string) ($w['group'] ?? ''));
        $out = [];
        foreach ($p->options ?? [] as $g) {
            $names = array_values(array_unique(array_map('strval', (array) ($byGroup->get($g['name'])['choices'] ?? []))));
            if ($names === []) {
                if ($g['required'] ?? false) {
                    throw ValidationException::withMessages(["items.{$i}" => ["Choose {$g['name']} for {$p->name}."]]);
                }

                continue;
            }
            if (! ($g['multiple'] ?? false) && count($names) > 1) {
                throw ValidationException::withMessages(["items.{$i}" => ["Choose one {$g['name']} for {$p->name}."]]);
            }
            $choices = [];
            foreach ($names as $n) {
                $c = collect($g['choices'] ?? [])->firstWhere('name', $n);
                if (! $c) {
                    throw ValidationException::withMessages(["items.{$i}" => ["'{$n}' is not an option for {$p->name}."]]);
                }
                $choices[] = ['name' => $c['name'], 'price' => round((float) $c['price'], 2)];
            }
            $out[] = ['group' => $g['name'], 'choices' => $choices];
            $byGroup->forget($g['name']);
        }
        if ($unknown = $byGroup->keys()->filter()->first()) {
            throw ValidationException::withMessages(["items.{$i}" => ["'{$unknown}' is not an option for {$p->name}."]]);
        }

        return $out;
    }

    /** Totals and the money split for an item subtotal and a delivery quote. */
    private function totals(Store $store, float $subtotal, array $delivery): array
    {
        $area = $store->serviceArea;
        $taxRate = (float) ($area?->tax_rate ?? 0);
        $taxLabel = $area?->tax_label ?: 'VAT';
        $itemsTax = $store->vat_registered && ! $store->prices_include_vat ? round($subtotal * $taxRate, 2) : 0.0;

        $feeEx = round(ShopSettings::value('shop.service_fee_flat') + $subtotal * ShopSettings::value('shop.service_fee_rate'), 2);
        $serviceFee = round($feeEx * (1 + $taxRate), 2);

        $deliveryFee = round((float) $delivery['fare'], 2);
        $deliveryEx = round((float) $delivery['subtotal'], 2);
        $total = round($subtotal + $itemsTax + $deliveryFee + $serviceFee, 2);

        $commissionRate = $store->commission_rate ?? ShopSettings::value('shop.commission_rate');
        $commission = round($subtotal * $commissionRate, 2);
        $platformDelivery = round($deliveryEx * ShopSettings::value('shop.delivery_share'), 2);

        $cur = $area?->currency ?? 'BBD';
        $km = $delivery['distance_m'] / 1000;
        $lines = array_values(array_filter([
            ['code' => 'items', 'label' => 'Items', 'amount' => $subtotal],
            $itemsTax > 0 ? ['code' => 'items_tax', 'label' => sprintf('%s on items (%s%%)', $taxLabel, rtrim(rtrim(number_format($taxRate * 100, 2), '0'), '.')), 'amount' => $itemsTax] : null,
            ['code' => 'delivery', 'label' => sprintf('Delivery (%.1f km)', $km), 'amount' => $deliveryFee],
            $serviceFee > 0 ? ['code' => 'service_fee', 'label' => 'Service fee', 'amount' => $serviceFee] : null,
        ]));

        return [
            'totals' => [
                'currency' => $cur, 'items_subtotal' => $subtotal, 'items_tax' => $itemsTax, 'delivery_fee' => $deliveryFee,
                'delivery_fee_ex_tax' => $deliveryEx, 'service_fee' => $serviceFee, 'total' => $total,
                'commission' => $commission, 'store_payout' => round($subtotal + $itemsTax - $commission, 2),
                'delivery_platform_share' => $platformDelivery, 'driver_earnings' => round($deliveryEx - $platformDelivery, 2),
            ],
            'breakdown' => [
                'lines' => $lines,
                'prices_include_vat' => $store->vat_registered && $store->prices_include_vat,
                'rates' => ['commission' => $commissionRate, 'delivery_share' => ShopSettings::value('shop.delivery_share'), 'tax' => $taxRate],
                'delivery' => $delivery['breakdown'] ?? null,
            ],
        ];
    }

    // ================================================================= customer

    /** Place an order: price it, hold the total on the card. 3-D Secure leaves it pending_payment. */
    public function place(User $customer, Store $store, array $lines, float $lat, float $lng, ?string $address, ?string $note, ?string $cardId): Order
    {
        $q = $this->quote($store, $lines, $lat, $lng);
        $card = $this->payments->cardForRide($customer, $cardId, $store->service_area_id);

        $order = DB::transaction(function () use ($customer, $store, $q, $lat, $lng, $address, $note, $card) {
            $o = Order::query()->create([
                'customer_id' => $customer->id, 'store_id' => $store->id, 'service_area_id' => $store->service_area_id,
                'payment_method_id' => $card->id, 'status' => OrderStatus::PendingPayment,
                ...collect($q['totals'])->except('currency')->all(), 'currency' => $q['totals']['currency'], 'breakdown' => $q['breakdown'],
                'dropoff_lat' => $lat, 'dropoff_lng' => $lng, 'dropoff_address' => $address ? mb_substr($address, 0, 255) : null,
                'distance_m' => $q['route']->distanceM, 'duration_s' => $q['route']->durationS,
                'note' => $note ? mb_substr(trim($note), 0, 500) : null,
            ]);
            $o->items()->createMany($q['lines']);

            return $o;
        });

        $this->payments->placeOrderHold($order, $card); // → onPaymentAuthorized / onPaymentFailed

        return $order->fresh(['items', 'store']);
    }

    /** The hold went through: the order goes to the store. */
    public function onPaymentAuthorized(Order $order): void
    {
        $o = DB::transaction(function () use ($order) {
            $o = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $o || $o->status !== OrderStatus::PendingPayment) {
                return null;
            }
            $o->forceFill(['status' => OrderStatus::Placed, 'payment_status' => 'authorized', 'placed_at' => now(),
                'expires_at' => now()->addMinutes(self::ACCEPT_MINUTES)])->save();

            return $o;
        });
        if ($o) {
            $this->alertStore($o);
        } elseif (($o = Order::query()->find($order->id)) && ! $o->isActive()) {
            $this->payments->releaseOrderHold($o); // authorised after it was cancelled
        }
    }

    public function onPaymentFailed(Order $order, ?string $reason): void
    {
        Order::query()->whereKey($order->id)->where('status', OrderStatus::PendingPayment->value)->update([
            'status' => OrderStatus::Cancelled->value, 'cancelled_by' => 'system', 'cancelled_at' => now(), 'payment_status' => 'failed',
            'decline_reason' => 'Card not accepted'.($reason ? ': '.$reason : ''),
        ]);
    }

    /** Customers can cancel until the store accepts; after that the store is already preparing it. */
    public function cancelByCustomer(Order $order, User $customer): Order
    {
        abort_unless($order->customer_id === $customer->id, 404);
        $o = $this->transition($order, [OrderStatus::PendingPayment, OrderStatus::Placed], OrderStatus::Cancelled,
            ['cancelled_by' => 'customer', 'cancelled_at' => now()], 'The store has already started on your order — contact the store to cancel.');
        $this->payments->releaseOrderHold($o);

        return $o;
    }

    /** Answer the store's request to take items off. No = the order is cancelled and the card released. */
    public function respondToChange(Order $order, User $customer, bool $accept): Order
    {
        abort_unless($order->customer_id === $customer->id, 404);
        if (! $order->hasPendingChange()) {
            throw ValidationException::withMessages(['change' => ['There is nothing to answer.']]);
        }
        $change = $order->change_request;
        if (! $accept) {
            $o = $this->transition($order, [OrderStatus::Placed, OrderStatus::Accepted], OrderStatus::Cancelled, [
                'cancelled_by' => 'customer', 'cancelled_at' => now(), 'decline_reason' => 'Customer did not accept the changes',
                'change_request' => ['status' => 'declined'] + $change,
            ]);
            $this->payments->releaseOrderHold($o);
            $this->notifyStore($o, 'Order '.$o->number().' cancelled: the customer did not accept the changes.');

            return $o;
        }

        DB::transaction(function () use ($order, $change) {
            $order->items()->whereIn('id', $change['item_ids'] ?? [])->update(['removed' => true]);
            $order->forceFill(['change_request' => ['status' => 'accepted'] + $change])->save();
            $this->recalculate($order);
        });

        return $order->fresh(['items', 'store']);
    }

    // ================================================================= store

    public function accept(Order $order, Store $store, int $prepMinutes): Order
    {
        $this->assertStore($order, $store);
        $prep = max(1, min(240, $prepMinutes));
        $o = $this->transition($order, [OrderStatus::Placed], OrderStatus::Accepted,
            ['accepted_at' => now(), 'prep_minutes' => $prep, 'ready_by' => now()->addMinutes($prep), 'expires_at' => null],
            'This order can no longer be accepted.');
        $this->notifyCustomer($o, "{$store->name} accepted your order", "Order {$o->number()} will be ready in about {$prep} minutes.");

        return $o;
    }

    public function decline(Order $order, Store $store, string $reason): Order
    {
        $this->assertStore($order, $store);
        $o = $this->transition($order, [OrderStatus::Placed, OrderStatus::Accepted], OrderStatus::Declined,
            ['decline_reason' => mb_substr($reason, 0, 255), 'cancelled_by' => 'store', 'cancelled_at' => now(), 'expires_at' => null]);
        $this->payments->releaseOrderHold($o);
        $this->notifyCustomer($o, "{$store->name} couldn't take your order", $reason.' Your card has not been charged.');

        return $o;
    }

    /** Store is out of something: ask the customer to accept the order without those items. */
    public function requestRemoval(Order $order, Store $store, array $itemIds, ?string $note): Order
    {
        $this->assertStore($order, $store);
        if (! in_array($order->status, [OrderStatus::Placed, OrderStatus::Accepted], true) || $order->hasPendingChange()) {
            throw ValidationException::withMessages(['items' => ['This order can\'t be changed now.']]);
        }
        $items = $order->items()->where('removed', false)->whereIn('id', $itemIds)->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Choose what you\'re out of.']]);
        }
        if ($items->count() === $order->items()->where('removed', false)->count()) {
            throw ValidationException::withMessages(['items' => ['That\'s everything — decline the order instead.']]);
        }
        $keep = round($order->items()->where('removed', false)->whereNotIn('id', $items->pluck('id'))->sum('line_total'), 2);
        $new = $this->totals($store, $keep, ['fare' => $order->delivery_fee, 'subtotal' => $order->delivery_fee_ex_tax, 'distance_m' => (int) $order->distance_m]);
        $order->forceFill(['change_request' => [
            'status' => 'pending', 'item_ids' => $items->pluck('id')->all(),
            'items' => $items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity])->all(),
            'note' => $note ? mb_substr($note, 0, 255) : null, 'new_total' => $new['totals']['total'], 'requested_at' => now()->toIso8601String(),
        ]])->save();
        $this->notifyCustomer($order, "{$store->name} is out of something",
            'Open the app to accept your order without '.$items->pluck('name')->implode(', ').' (new total '.$order->currency.' '.number_format($new['totals']['total'], 2).').', urgent: true);

        return $order->fresh(['items', 'store']);
    }

    /** Packed and ready: take the payment (phase 3 then sends a driver). */
    public function markReady(Order $order, Store $store): Order
    {
        $this->assertStore($order, $store);
        if ($order->hasPendingChange()) {
            throw ValidationException::withMessages(['status' => ['Wait for the customer to answer the item change first.']]);
        }
        $o = $this->transition($order, [OrderStatus::Accepted], OrderStatus::Ready, ['ready_at' => now()], 'Accept the order before marking it ready.');
        $this->payments->captureOrder($o);
        $this->notifyCustomer($o, 'Your order is ready', "{$store->name} has packed order {$o->number()}.");

        return $o->fresh(['items', 'store']);
    }

    /** Orders the store never answered: release the card and tell the customer (scheduler, every minute). */
    public function expireUnanswered(): int
    {
        $n = 0;
        Order::query()->where('status', OrderStatus::Placed->value)->where('expires_at', '<=', now())->get()->each(function (Order $o) use (&$n) {
            $done = Order::query()->whereKey($o->id)->where('status', OrderStatus::Placed->value)
                ->update(['status' => OrderStatus::Expired->value, 'cancelled_by' => 'system', 'cancelled_at' => now(), 'expires_at' => null]);
            if ($done) {
                $o->refresh();
                $this->payments->releaseOrderHold($o);
                $this->notifyCustomer($o, 'Order not accepted', "{$o->store->name} didn't respond in time. Your card has not been charged.");
                $n++;
            }
        });

        return $n;
    }

    // ================================================================= internals

    private function assertStore(Order $order, Store $store): void
    {
        abort_unless($order->store_id === $store->id, 404);
    }

    /** @param OrderStatus[] $from */
    private function transition(Order $order, array $from, OrderStatus $to, array $attrs, ?string $error = null): Order
    {
        return DB::transaction(function () use ($order, $from, $to, $attrs, $error) {
            $o = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($o->status, $from, true)) {
                throw ValidationException::withMessages(['status' => [$error ?? 'This order has already moved on.']]);
            }
            $o->forceFill(['status' => $to] + $attrs)->save();

            return $o->load(['items', 'store']);
        });
    }

    private function recalculate(Order $order): void
    {
        $keep = round($order->items()->where('removed', false)->sum('line_total'), 2);
        $t = $this->totals($order->store, $keep, ['fare' => $order->delivery_fee, 'subtotal' => $order->delivery_fee_ex_tax, 'distance_m' => (int) $order->distance_m]);
        $order->forceFill(collect($t['totals'])->except('currency')->all() + ['breakdown' => $t['breakdown']])->save();
    }

    private function alertStore(Order $o): void
    {
        $o->loadMissing('store');
        $this->notifyStore($o, "New Ride Coop order {$o->number()} ({$o->currency} ".number_format($o->total, 2).'). Accept it in the store portal within '.self::ACCEPT_MINUTES.' minutes.');
        // Store staff who use the apps on their phone get a push too.
        foreach ($o->store->users()->pluck('users.id') as $uid) {
            $this->push->notify($uid, new PushMessage('New order '.$o->number(), "Accept it in the store portal within ".self::ACCEPT_MINUTES.' minutes.',
                ['type' => 'store_order', 'order_id' => $o->public_id], channel: 'ride_offers', ttlSeconds: self::ACCEPT_MINUTES * 60, urgent: true));
        }
    }

    private function notifyStore(Order $o, string $text): void
    {
        $phone = $o->store?->phone;
        if (! $phone) {
            return;
        }
        try {
            app(\App\Contracts\SmsSender::class)->send(\App\Support\Phone::normalise($phone), $text);
        } catch (\Throwable) {
            // The portal shows new orders anyway.
        }
    }

    private function notifyCustomer(Order $o, string $title, string $body, bool $urgent = false): void
    {
        $this->push->notify($o->customer_id, new PushMessage($title, $body,
            ['type' => 'order', 'order_id' => $o->public_id, 'status' => $o->status->value], collapseKey: 'order-'.$o->public_id, urgent: $urgent));
    }
}
