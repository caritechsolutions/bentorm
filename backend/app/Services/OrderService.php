<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod as PayBy;
use App\Enums\TripStatus;
use App\Events\TripUpdated;
use App\Filament\Pages\ShopSettings;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Store;
use App\Models\Trip;
use App\Models\User;
use App\Support\Geo;
use App\Support\PushMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shop orders: price a cart, place it (card hold), and the store's side — accept, decline, remove items with
 * the customer's OK, mark ready. Unanswered orders expire and the hold is released.
 *
 * Delivery: a driver is sent when the order will be ready within the "driver lead" minutes (or straight away when
 * the store marks it ready / asks for a driver now). The delivery is a trip (store → customer) offered to drivers
 * who opted in to deliveries. Nobody takes it → a new job next minute; after the alert time the store, admins and
 * customer are told (the customer may then cancel). The store confirms the hand-over ("Picked up"); the driver
 * finishes with the customer's PIN, and only then is the card charged.
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
        private readonly DispatchService $dispatch,
    ) {}

    // ================================================================= pricing

    /**
     * @param  array<int, array{product_id: int, quantity: int, options?: array, note?: ?string}>  $lines
     * @return array{store: Store, lines: array, totals: array, breakdown: array, route: mixed, dropoff: mixed}
     */
    public function quote(Store $store, array $lines, float $lat, float $lng, array $extras = []): array
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

        $x = $this->withPromoAndCredit($store, $subtotal, $this->checkTip($extras));

        return ['store' => $store, 'lines' => $priced, 'route' => $delivery['route'], 'dropoff' => $to, 'promo' => $x['promo'] ?? null]
            + $this->totals($store, $subtotal, $delivery, $x);
    }

    /**
     * extras.promo_code (+ extras.user) → a checked code; extras.use_credit → the customer's credit balance. The amounts
     * themselves are worked out in totals() once the full price is known.
     */
    private function withPromoAndCredit(Store $store, float $subtotal, array $extras): array
    {
        $user = $extras['user'] ?? null;
        if (filled($extras['promo_code'] ?? null)) {
            $extras['promo'] = app(PromoService::class)->check($extras['promo_code'], $user, 'shop', $subtotal, $store->service_area_id);
            $extras['promo_code'] = $extras['promo']->code;
        } else {
            unset($extras['promo_code']);
        }
        if (($extras['use_credit'] ?? false) && $user) {
            $extras['credit_balance'] = \App\Models\CreditEntry::balance($user->id);
        }

        return $extras;
    }

    /** A checkout tip: allowed when tips are on, up to the maximum. */
    private function checkTip(array $extras): array
    {
        $tip = (float) ($extras['tip'] ?? 0);
        if ($tip > 0) {
            \App\Support\Features::require('tips', 'Tipping');
            $max = \App\Support\Features::number('tips.max_amount');
            if ($tip > $max) {
                throw ValidationException::withMessages(['tip' => [sprintf('The largest tip is %.2f.', $max)]]);
            }
        }

        return $extras;
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
    /**
     * Totals and the money split for an item subtotal and a delivery quote. $extras: tip (to the driver),
     * discount (promo, paid by Ride Coop) and credit (the customer's ride credit). Discount + credit never take the
     * card charge below 1.00, and never touch what the store or driver gets.
     */
    private function totals(Store $store, float $subtotal, array $delivery, array $extras = []): array
    {
        $area = $store->serviceArea;
        $taxRate = (float) ($area?->tax_rate ?? 0);
        $taxLabel = $area?->tax_label ?: 'VAT';
        $itemsTax = $store->vat_registered && ! $store->prices_include_vat ? round($subtotal * $taxRate, 2) : 0.0;

        $feeEx = round(ShopSettings::value('shop.service_fee_flat') + $subtotal * ShopSettings::value('shop.service_fee_rate'), 2);
        $serviceFee = round($feeEx * (1 + $taxRate), 2);

        $deliveryFee = round((float) $delivery['fare'], 2);
        $deliveryEx = round((float) $delivery['subtotal'], 2);
        $tip = round(max(0, (float) ($extras['tip'] ?? 0)), 2);
        $gross = round($subtotal + $itemsTax + $deliveryFee + $serviceFee + $tip, 2);
        $wanted = isset($extras['promo']) ? $extras['promo']->discountOn($gross - $tip) : (float) ($extras['discount'] ?? 0);
        $discount = round(min(max(0, $wanted), max(0, $gross - 1)), 2);
        $wantedCredit = isset($extras['credit_balance'])
            ? min($extras['credit_balance'], ($gross - $discount) * \App\Support\Features::number('credit.max_share')) : (float) ($extras['credit'] ?? 0);
        $credit = round(min(max(0, $wantedCredit), max(0, $gross - $discount - 1)), 2);
        $total = round($gross - $discount - $credit, 2);

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
            $tip > 0 ? ['code' => 'tip', 'label' => 'Tip for your driver', 'amount' => $tip] : null,
            $discount > 0 ? ['code' => 'discount', 'label' => 'Promo'.(isset($extras['promo_code']) ? ' '.$extras['promo_code'] : ''), 'amount' => -$discount] : null,
            $credit > 0 ? ['code' => 'credit', 'label' => 'Ride credit', 'amount' => -$credit] : null,
        ]));

        return [
            'totals' => [
                'currency' => $cur, 'items_subtotal' => $subtotal, 'items_tax' => $itemsTax, 'delivery_fee' => $deliveryFee,
                'delivery_fee_ex_tax' => $deliveryEx, 'service_fee' => $serviceFee, 'tip' => $tip, 'discount' => $discount, 'credit' => $credit, 'total' => $total,
                'commission' => $commission, 'store_payout' => round($subtotal + $itemsTax - $commission, 2),
                'delivery_platform_share' => $platformDelivery, 'driver_earnings' => round($deliveryEx - $platformDelivery, 2),
            ],
            'breakdown' => [
                'lines' => $lines,
                'prices_include_vat' => $store->vat_registered && $store->prices_include_vat,
                'rates' => ['commission' => $commissionRate, 'delivery_share' => ShopSettings::value('shop.delivery_share'), 'tax' => $taxRate],
                'delivery' => $delivery['breakdown'] ?? null,
                'promo_code' => $extras['promo_code'] ?? null,
            ],
        ];
    }

    // ================================================================= customer

    /** Place an order: price it, hold the total on the card. 3-D Secure leaves it pending_payment. */
    public function place(User $customer, Store $store, array $lines, float $lat, float $lng, ?string $address, ?string $note, ?string $cardId, array $extras = []): Order
    {
        $q = $this->quote($store, $lines, $lat, $lng, ['user' => $customer] + $extras);
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
            if ($q['promo']) {
                $o->forceFill(['promo_code_id' => $q['promo']->id])->save();
            }
            app(PromoService::class)->onOrderPlaced($o->setRelation('customer', $customer), $q['promo']);

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
        $done = Order::query()->whereKey($order->id)->where('status', OrderStatus::PendingPayment->value)->update([
            'status' => OrderStatus::Cancelled->value, 'cancelled_by' => 'system', 'cancelled_at' => now(), 'payment_status' => 'failed',
            'decline_reason' => 'Card not accepted'.($reason ? ': '.$reason : ''),
        ]);
        if ($done) {
            app(PromoService::class)->onOrderEnded($order->fresh());
        }
    }

    /**
     * Customers can cancel until the store accepts (the store is already preparing it after that) — or when no driver
     * has taken the delivery for a long time (staff were alerted), as long as no driver is on it.
     */
    public function cancelByCustomer(Order $order, User $customer): Order
    {
        abort_unless($order->customer_id === $customer->id, 404);
        $from = [OrderStatus::PendingPayment, OrderStatus::Placed];
        if ($this->customerMayCancelForNoDriver($order)) {
            $from = [...$from, OrderStatus::Accepted, OrderStatus::Ready];
        }
        $o = $this->transition($order, $from, OrderStatus::Cancelled,
            ['cancelled_by' => 'customer', 'cancelled_at' => now()], 'The store has already started on your order — contact the store to cancel.');
        $this->cancelDeliveryJob($o, 'Customer cancelled');
        $this->payments->releaseOrderHold($o);
        if ($o->accepted_at) {
            $this->notifyStore($o, "Order {$o->number()} was cancelled by the customer (no driver was found). Nothing was charged.");
        }

        return $o;
    }

    /** No driver for a long time and none on the way: the customer may cancel. */
    public function customerMayCancelForNoDriver(Order $o): bool
    {
        return $o->driver_alert_at !== null && in_array($o->status, [OrderStatus::Accepted, OrderStatus::Ready], true)
            && ! $o->activeDelivery()?->driver_id;
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
            $this->cancelDeliveryJob($o, 'Customer did not accept the changes');
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
            ['accepted_at' => now(), 'prep_minutes' => $prep, 'ready_by' => now()->addMinutes($prep), 'expires_at' => null,
                'delivery_pin' => $order->delivery_pin ?? TripService::newPin()],
            'This order can no longer be accepted.');
        $this->notifyCustomer($o, "{$store->name} accepted your order", "Order {$o->number()} will be ready in about {$prep} minutes.");

        return $o;
    }

    public function decline(Order $order, Store $store, string $reason): Order
    {
        $this->assertStore($order, $store);
        $o = $this->transition($order, [OrderStatus::Placed, OrderStatus::Accepted], OrderStatus::Declined,
            ['decline_reason' => mb_substr($reason, 0, 255), 'cancelled_by' => 'store', 'cancelled_at' => now(), 'expires_at' => null]);
        $this->cancelDeliveryJob($o, 'Store cancelled the order');
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
        $new = $this->totals($store, $keep, ['fare' => $order->delivery_fee, 'subtotal' => $order->delivery_fee_ex_tax, 'distance_m' => (int) $order->distance_m], $this->extrasOf($order));
        $order->forceFill(['change_request' => [
            'status' => 'pending', 'item_ids' => $items->pluck('id')->all(),
            'items' => $items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity])->all(),
            'note' => $note ? mb_substr($note, 0, 255) : null, 'new_total' => $new['totals']['total'], 'requested_at' => now()->toIso8601String(),
        ]])->save();
        $this->notifyCustomer($order, "{$store->name} is out of something",
            'Open the app to accept your order without '.$items->pluck('name')->implode(', ').' (new total '.$order->currency.' '.number_format($new['totals']['total'], 2).').', urgent: true);

        return $order->fresh(['items', 'store']);
    }

    /** Packed and ready: a driver is sent now if one isn't already on the way. */
    public function markReady(Order $order, Store $store): Order
    {
        $this->assertStore($order, $store);
        if ($order->hasPendingChange()) {
            throw ValidationException::withMessages(['status' => ['Wait for the customer to answer the item change first.']]);
        }
        $o = $this->transition($order, [OrderStatus::Accepted], OrderStatus::Ready, ['ready_at' => now()], 'Accept the order before marking it ready.');
        $this->sendDriver($o);
        $this->notifyCustomer($o, 'Your order is ready', "{$store->name} has packed order {$o->number()}. A driver will bring it to you.");

        return $o->fresh(['items', 'store']);
    }

    /** Store: "Need a driver now" — don't wait for the prep timer. */
    public function requestDriverNow(Order $order, Store $store): Order
    {
        $this->assertStore($order, $store);
        if (! in_array($order->status, [OrderStatus::Accepted, OrderStatus::Ready], true)) {
            throw ValidationException::withMessages(['status' => ['Accept the order first.']]);
        }
        $this->sendDriver($order);

        return $order->fresh(['items', 'store']);
    }

    /** The store handed the bag to the driver. */
    public function pickedUp(Order $order, Store $store): Order
    {
        $this->assertStore($order, $store);
        if ($order->hasPendingChange()) {
            throw ValidationException::withMessages(['status' => ['Wait for the customer to answer the item change first.']]);
        }
        $trip = $order->activeDelivery();
        if (! $trip?->driver_id) {
            throw ValidationException::withMessages(['status' => ['No driver has taken this delivery yet.']]);
        }
        app(TripService::class)->deliveryPickedUp($trip);
        $o = $this->transition($order, [OrderStatus::Accepted, OrderStatus::Ready], OrderStatus::PickedUp,
            ['picked_up_at' => now(), 'ready_at' => $order->ready_at ?? now()], 'This order has already moved on.');
        $name = $trip->driver?->firstName() ?? 'Your driver';
        $this->notifyCustomer($o, 'Your order is on the way', "{$name} is bringing order {$o->number()}. Have your PIN ready — it's in the app.", urgent: true);

        return $o->fresh(['items', 'store']);
    }

    /** The driver entered the customer's PIN: the order is done and the card is charged. */
    public function onDelivered(Order $order): Order
    {
        $o = $this->transition($order, [OrderStatus::PickedUp], OrderStatus::Delivered, ['delivered_at' => now()]);
        $this->payments->captureOrder($o);
        $this->notifyCustomer($o, 'Delivered', "Order {$o->number()} from {$o->store->name} was delivered. Enjoy!");

        return $o->fresh(['items', 'store']);
    }

    /**
     * Scheduler, every minute: send a driver when the order will be ready within the lead time (or was asked for),
     * send a fresh job when the last one found nobody or the driver cancelled, and alert people after a while.
     */
    public function dispatchDue(): int
    {
        $lead = (int) ShopSettings::value('shop.driver_lead_minutes');
        $sent = 0;
        Order::query()->whereIn('status', [OrderStatus::Accepted->value, OrderStatus::Ready->value])->with('store')->get()
            ->each(function (Order $o) use ($lead, &$sent) {
                $due = $o->driver_requested_at !== null || $o->status === OrderStatus::Ready
                    || ($o->ready_by !== null && $o->ready_by->lte(now()->addMinutes($lead)));
                if ($due && ! $o->hasPendingChange() && $this->sendDriver($o)) {
                    $sent++;
                }
                $this->alertIfNoDriver($o->fresh());
            });

        return $sent;
    }

    /** Create a delivery job and start offering it, unless one is already running. True when a new one was sent. */
    public function sendDriver(Order $order): bool
    {
        $trip = DB::transaction(function () use ($order) {
            $o = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($o->status, [OrderStatus::Accepted, OrderStatus::Ready], true) || $o->activeDelivery()) {
                return null;
            }
            $store = $o->store;
            $o->forceFill(['delivery_pin' => $o->delivery_pin ?? TripService::newPin(), 'driver_requested_at' => $o->driver_requested_at ?? now()])->save();

            return Trip::query()->create([
                'rider_id' => $o->customer_id, 'order_id' => $o->id, 'service_area_id' => $o->service_area_id,
                'vehicle_type' => 'delivery', 'status' => TripStatus::Requested,
                'pickup_lat' => $store->lat, 'pickup_lng' => $store->lng,
                'pickup_address' => mb_substr(trim($store->name.($store->address ? ', '.$store->address : '')), 0, 255),
                'dropoff_lat' => $o->dropoff_lat, 'dropoff_lng' => $o->dropoff_lng, 'dropoff_address' => $o->dropoff_address,
                'pin' => $o->delivery_pin,
                'estimated_distance_m' => $o->distance_m, 'estimated_duration_s' => $o->duration_s, 'estimated_fare' => $o->delivery_fee,
                'currency' => $o->currency, 'payment_method' => PayBy::Card, 'payment_status' => 'pending',
                'passengers' => 1, 'luggage' => 0, 'rider_note' => $o->note, 'requested_at' => now(),
            ]);
        });
        if (! $trip) {
            return false;
        }
        $this->dispatch->start($trip);
        event(new TripUpdated($trip));

        return true;
    }

    /** Nobody has taken it for the alert time: tell the store, admins and the customer (once). */
    private function alertIfNoDriver(Order $o): void
    {
        $minutes = (int) ShopSettings::value('shop.driver_alert_minutes');
        if ($o->driver_alert_at || ! $o->driver_requested_at || $o->driver_requested_at->gt(now()->subMinutes($minutes))
            || ! in_array($o->status, [OrderStatus::Accepted, OrderStatus::Ready], true) || $o->activeDelivery()?->driver_id) {
            return;
        }
        if (! Order::query()->whereKey($o->id)->whereNull('driver_alert_at')->update(['driver_alert_at' => now()])) {
            return;
        }
        $text = "No driver has taken order {$o->number()} yet ({$minutes} min). We keep looking; the customer may cancel.";
        $this->notifyStore($o, "Ride Coop: {$text}");
        $msg = new PushMessage('No driver yet for order '.$o->number(), $text, ['type' => 'store_order', 'order_id' => $o->public_id], urgent: true);
        foreach ($o->store->users()->pluck('users.id')->merge(User::query()->where('role', 'admin')->pluck('id'))->unique() as $uid) {
            $this->push->notify($uid, $msg);
        }
        $this->notifyCustomer($o, 'Still looking for a driver', "Drivers are busy right now. We keep trying — or you can cancel order {$o->number()} in the app and nothing is charged.");
    }

    /** Stop a delivery job that is still looking for a driver or on its way to the store. */
    private function cancelDeliveryJob(Order $o, string $reason): void
    {
        $t = $o->activeDelivery();
        if (! $t || $t->status === TripStatus::InProgress) {
            return; // the driver already has the bag — staff sort that out
        }
        $this->dispatch->withdrawAll($t, 'cancelled');
        $t->forceFill(['status' => TripStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => 'system',
            'cancellation_reason' => $reason, 'payment_status' => 'waived'])->save();
        if ($t->driver && $t->driver->driverProfile?->is_online) {
            app(DriverLocationService::class)->setState($t->driver, 'idle');
        }
        event(new TripUpdated($t));
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
                app(PromoService::class)->onOrderEnded($o);
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

    /** Tip / promo / credit already on an order, for recalculating after items change. */
    private function extrasOf(Order $order): array
    {
        return ['tip' => $order->tip, 'discount' => $order->discount, 'credit' => $order->credit, 'promo_code' => $order->breakdown['promo_code'] ?? null];
    }

    private function recalculate(Order $order): void
    {
        $keep = round($order->items()->where('removed', false)->sum('line_total'), 2);
        $t = $this->totals($order->store, $keep, ['fare' => $order->delivery_fee, 'subtotal' => $order->delivery_fee_ex_tax, 'distance_m' => (int) $order->distance_m], $this->extrasOf($order));
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
