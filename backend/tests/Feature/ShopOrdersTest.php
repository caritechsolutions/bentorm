<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Pages\ShopSettings;
use App\Filament\Store\Resources\Orders\Pages\ListOrders;
use App\Filament\Store\Resources\Orders\Pages\ViewOrder as StoreViewOrder;
use App\Jobs\SendPushJob;
use App\Models\AppSetting;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceArea;
use App\Models\ShopCategory;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ShopOrdersTest extends TestCase
{
    use RefreshDatabase;

    private ServiceArea $bb;

    private Store $store;

    private User $owner;

    private User $customer;

    private Product $fish;

    private Product $drink;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendPushJob::class]);
        $this->bb = $this->seedReference();
        $this->owner = $this->makeUser('rider');
        $this->store = Store::query()->create(['name' => 'Fish Pot', 'shop_category_id' => ShopCategory::query()->where('slug', 'food')->value('id'),
            'phone' => '2464281234', 'address' => 'Oistins', 'lat' => self::OISTINS[0], 'lng' => self::OISTINS[1], 'prep_minutes' => 20, 'min_order' => 10]);
        $this->store->forceFill(['status' => 'approved', 'is_open' => true])->save();
        $this->store->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->fish = $this->store->products()->create(['name' => 'Fried flying fish', 'price' => 22.50, 'options' => [
            ['name' => 'Side', 'required' => true, 'multiple' => false, 'choices' => [['name' => 'Macaroni pie', 'price' => 0], ['name' => 'Breadfruit', 'price' => 3]]],
            ['name' => 'Extras', 'required' => false, 'multiple' => true, 'choices' => [['name' => 'Pepper sauce', 'price' => 0.5], ['name' => 'Salad', 'price' => 2]]],
        ]]);
        $this->drink = $this->store->products()->create(['name' => 'Mauby', 'price' => 4]);
        $this->customer = $this->makeUser('rider');
        DeviceToken::query()->create(['user_id' => $this->customer->id, 'token' => 'cust-phone', 'platform' => 'android']);
    }

    private function cart(array $extra = []): array
    {
        return $extra + [
            'store' => $this->store->slug, 'lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1],
            'items' => [
                ['product_id' => $this->fish->id, 'quantity' => 2, 'options' => [['group' => 'Side', 'choices' => ['Breadfruit']], ['group' => 'Extras', 'choices' => ['Pepper sauce', 'Salad']]], 'note' => 'No onions'],
                ['product_id' => $this->drink->id, 'quantity' => 1],
            ],
        ];
    }

    private function addCard(string $number = '4242424242424242'): string
    {
        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/me/payment-methods', ['lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]])->assertCreated();
        $form = $this->get($res->json('redirect_url'))->assertOk();
        preg_match('/action="([^"]+)"/', $form->getContent(), $m);
        $this->post(html_entity_decode($m[1]), ['number' => $number, 'exp_month' => 12, 'exp_year' => now()->year + 2])->assertRedirect();
        $this->get(route('payment-methods.return', ['paymentMethod' => $res->json('payment_method.id')]))->assertOk();

        return $res->json('payment_method.id');
    }

    private function place(array $extra = []): Order
    {
        $this->addCard();
        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/shop/orders', $this->cart($extra + ['address' => 'Bay Street', 'note' => 'Blue gate']))->assertCreated();

        return Order::query()->where('public_id', $res->json('id'))->firstOrFail();
    }

    private function asStore(): void
    {
        $this->actingAs($this->owner, 'web'); // the portal uses the web guard; API calls switch the default to sanctum
        Filament::setCurrentPanel(Filament::getPanel('store'));
        Filament::setTenant($this->store);
        Filament::bootCurrentPanel();
    }

    public function test_browse_categories_stores_and_the_menu_without_signing_in(): void
    {
        $this->store->products()->create(['name' => 'Sold out cake', 'price' => 9, 'is_available' => false]);
        $closed = Store::query()->create(['name' => 'Closed Cafe', 'shop_category_id' => $this->store->shop_category_id, 'lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]]);
        $closed->forceFill(['status' => 'approved', 'is_open' => false])->save();
        Store::query()->create(['name' => 'Pending Place', 'shop_category_id' => $this->store->shop_category_id, 'lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]]);

        $food = collect($this->getJson('/api/v1/shop/categories?lat=13.0969&lng=-59.6145')->assertOk()->json())->firstWhere('slug', 'food');
        $this->assertSame([2, 1], [$food['stores'], $food['open_now']], 'pending stores are never shown');

        $stores = $this->getJson('/api/v1/shop/stores?category=food&lat=13.0969&lng=-59.6145')->assertOk()->json();
        $this->assertSame(['Fish Pot', 'Closed Cafe'], array_column($stores, 'name'), 'open first');
        $this->assertSame([true, false], array_column($stores, 'open_now'));
        $this->assertSame(true, $stores[0]['delivers_here']);
        $this->assertSame(['Fish Pot'], array_column($this->getJson('/api/v1/shop/stores?q=mauby')->json(), 'name'), 'search finds products too');

        $menu = $this->getJson("/api/v1/shop/stores/{$this->store->slug}")->assertOk()->json();
        $products = collect($menu['sections'])->flatMap(fn ($s) => $s['products']);
        $this->assertSame(['Fried flying fish', 'Mauby', 'Sold out cake'], $products->pluck('name')->sort()->values()->all());
        $this->assertFalse($products->firstWhere('name', 'Sold out cake')['available']);
        $this->getJson('/api/v1/shop/stores/pending-place')->assertNotFound();
    }

    public function test_quote_prices_items_options_delivery_and_fees(): void
    {
        AppSetting::put('shop.service_fee_flat', 1.0);
        $q = $this->postJson('/api/v1/shop/quote', $this->cart())->assertOk()->json();
        // fish: 22.50 + 3 (breadfruit) + 0.50 + 2 = 28.00 × 2 = 56.00; mauby 4.00
        $this->assertSame([28.0, 56.0], [$q['items'][0]['unit_price'], $q['items'][0]['line_total']]);
        $this->assertSame(60.0, $q['totals']['items']);
        $this->assertSame(0.0, $q['totals']['items_tax'], 'not VAT registered: nothing added');
        $this->assertSame(1.18, $q['totals']['service_fee'], '1.00 + 17.5% VAT');
        $this->assertGreaterThanOrEqual(6.0, $q['totals']['delivery'], 'at least the delivery minimum');
        $this->assertEqualsWithDelta(60 + 1.18 + $q['totals']['delivery'], $q['totals']['total'], 0.001);
        $this->assertSame(['items', 'delivery', 'service_fee'], array_column($q['lines'], 'code'));

        // Prices excluding VAT: VAT is added on the items.
        $this->store->update(['vat_registered' => true, 'prices_include_vat' => false]);
        $q2 = $this->postJson('/api/v1/shop/quote', $this->cart())->assertOk()->json();
        $this->assertSame(10.5, $q2['totals']['items_tax']);
    }

    public function test_quote_rejects_what_the_store_cannot_do(): void
    {
        $bad = fn (array $items) => $this->postJson('/api/v1/shop/quote', $this->cart(['items' => $items]))->assertUnprocessable()->json('errors');
        $this->assertStringContainsString('Choose Side', $bad([['product_id' => $this->fish->id, 'quantity' => 1]])['items.0'][0]);
        $this->assertStringContainsString('Choose one Side', $bad([['product_id' => $this->fish->id, 'quantity' => 1, 'options' => [['group' => 'Side', 'choices' => ['Macaroni pie', 'Breadfruit']]]]])['items.0'][0]);
        $this->assertStringContainsString("'Chips' is not an option", $bad([['product_id' => $this->fish->id, 'quantity' => 1, 'options' => [['group' => 'Side', 'choices' => ['Chips']]]]])['items.0'][0]);
        $this->assertStringContainsString('minimum order', $bad([['product_id' => $this->drink->id, 'quantity' => 1]])['items'][0]);
        $this->drink->update(['is_available' => false]);
        $this->assertStringContainsString('sold out', $bad([['product_id' => $this->drink->id, 'quantity' => 3]])['items.0'][0]);
        $other = Store::query()->create(['name' => 'Other', 'shop_category_id' => $this->store->shop_category_id, 'lat' => 13.1, 'lng' => -59.6]);
        $foreign = $other->products()->create(['name' => 'Elsewhere', 'price' => 50]);
        $this->assertStringContainsString('no longer on the menu', $bad([['product_id' => $foreign->id, 'quantity' => 1]])['items.0'][0]);

        $this->store->update(['delivery_radius_km' => 3]);
        $this->assertStringContainsString('delivers up to 3 km', $this->postJson('/api/v1/shop/quote', $this->cart())->assertUnprocessable()->json('errors.dropoff.0'));
        $this->postJson('/api/v1/shop/quote', $this->cart(['lat' => self::ST_LUCIA[0], 'lng' => self::ST_LUCIA[1]]))->assertUnprocessable()->assertJsonValidationErrors('dropoff');
        $this->store->update(['is_open' => false]);
        $this->postJson('/api/v1/shop/quote', $this->cart())->assertUnprocessable()->assertJsonPath('errors.store.0', 'Fish Pot is closed right now.');
    }

    public function test_order_holds_the_card_store_accepts_marks_ready_and_the_card_is_charged(): void
    {
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/shop/orders', $this->cart())->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');

        $order = $this->place();
        $this->assertSame([OrderStatus::Placed, 'authorized'], [$order->status, $order->payment_status]);
        $this->assertSame((float) $order->total, (float) $order->hold()->authorized_amount);
        $this->assertTrue($order->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)));
        $this->assertSame(['Blue gate', 'Bay Street', 'No onions'], [$order->note, $order->dropoff_address, $order->items[0]->note]);
        $this->assertEquals([['group' => 'Side', 'choices' => [['name' => 'Breadfruit', 'price' => 3.0]]], ['group' => 'Extras', 'choices' => [['name' => 'Pepper sauce', 'price' => 0.5], ['name' => 'Salad', 'price' => 2.0]]]], $order->items[0]->options);
        // Commission 15% of items; store gets the rest; delivery split 20/80.
        $this->assertSame([9.0, 51.0], [$order->commission, $order->store_payout]);
        $this->assertEqualsWithDelta($order->delivery_fee_ex_tax, $order->delivery_platform_share + $order->driver_earnings, 0.001);

        $active = $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/shop/orders/active')->assertOk()->json();
        $this->assertSame([$order->public_id, 'placed', $order->number()], [$active[0]['id'], $active[0]['status'], $active[0]['number']]);
        $this->getJson('/api/v1/shop/orders/'.$order->public_id)->assertOk()->assertJsonPath('items.0.options_text', 'Side: Breadfruit · Extras: Pepper sauce, Salad');
        $this->actingAs($this->makeUser(), 'sanctum')->getJson('/api/v1/shop/orders/'.$order->public_id)->assertNotFound();

        // Store side, in the portal.
        $this->asStore();
        $this->get("/store/{$this->store->slug}/orders")->assertOk()->assertSee($order->number());
        $this->get(route('store.order-alerts', $this->store))->assertOk()->assertJson(['placed' => [$order->public_id]]);
        Livewire::test(ListOrders::class)->callTableAction('accept', $order, ['prep_minutes' => 25])->assertHasNoTableActionErrors();
        $order->refresh();
        $this->assertSame([OrderStatus::Accepted, 25], [$order->status, $order->prep_minutes]);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $this->customer->id && str_contains($j->message->title, 'accepted your order'));
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/shop/orders/{$order->public_id}/cancel")->assertUnprocessable();

        $this->asStore();
        Livewire::test(StoreViewOrder::class, ['record' => $order->public_id])->callAction('ready')->assertHasNoActionErrors();
        $order->refresh();
        $this->assertSame([OrderStatus::Ready, 'paid', 'captured'], [$order->status, $order->payment_status, $order->hold()->status]);
        $this->assertEqualsWithDelta($order->total, $order->hold()->captured_amount, 0.001);
    }

    public function test_out_of_stock_items_need_the_customers_ok(): void
    {
        $order = $this->place();
        $this->asStore();
        $drinkLine = $order->items->firstWhere('name', 'Mauby');
        Livewire::test(ListOrders::class)->callTableAction('outOfStock', $order, ['item_ids' => [$drinkLine->id], 'note' => 'Ran out'])->assertHasNoTableActionErrors();
        $order->refresh();
        $this->assertTrue($order->hasPendingChange());
        $this->assertEqualsWithDelta($order->total - 4, $order->change_request['new_total'], 0.001);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $this->customer->id && $j->message->urgent && str_contains($j->message->body, 'Mauby'));

        $old = $order->total;
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/shop/orders/{$order->public_id}/change", ['accept' => true])->assertOk()
            ->assertJsonPath('items.1.removed', true)->assertJsonPath('change_request.status', 'accepted');
        $order->refresh();
        $this->assertEqualsWithDelta($old - 4, $order->total, 0.001);
        $this->assertSame([56.0, 8.4, 47.6], [$order->items_subtotal, $order->commission, $order->store_payout]);

        // Ready charges the smaller amount from the bigger hold.
        $this->asStore();
        app(OrderService::class)->accept($order, $this->store, 10);
        app(OrderService::class)->markReady($order->fresh(), $this->store);
        $this->assertEqualsWithDelta($order->fresh()->total, $order->hold()->captured_amount, 0.001);
    }

    public function test_customer_says_no_to_changes_cancels_and_releases(): void
    {
        $order = $this->place();
        app(OrderService::class)->requestRemoval($order, $this->store, [$order->items[1]->id], null);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/shop/orders/{$order->public_id}/change", ['accept' => false])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(['voided', 'released'], [$order->hold()->status, $order->fresh()->payment_status]);
    }

    public function test_cancel_decline_and_expiry_release_the_card(): void
    {
        $a = $this->place();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/shop/orders/{$a->public_id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame('voided', $a->hold()->status);

        $b = $this->place();
        $this->asStore();
        Livewire::test(ListOrders::class)->callTableAction('decline', $b, ['reason' => 'We are too busy right now.'])->assertHasNoTableActionErrors();
        $this->assertSame([OrderStatus::Declined, 'We are too busy right now.', 'voided'], [$b->fresh()->status, $b->fresh()->decline_reason, $b->hold()->status]);

        $c = $this->place();
        $this->travel(6)->minutes();
        $this->assertSame(1, app(OrderService::class)->expireUnanswered());
        $this->assertSame([OrderStatus::Expired, 'voided'], [$c->fresh()->status, $c->hold()->status]);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $this->customer->id && $j->message->title === 'Order not accepted');
    }

    public function test_3ds_then_declined_cards(): void
    {
        $this->addCard('4000000000003220');
        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/shop/orders', $this->cart())->assertCreated()->assertJsonPath('status', 'pending_payment');
        $page = $this->get($res->json('payment.redirect_url'))->assertOk();
        preg_match('/action="([^"]+approve[^"]*)"/', $page->getContent(), $m);
        $this->post(html_entity_decode($m[1]))->assertRedirect();
        $this->assertSame(OrderStatus::Placed, Order::query()->where('public_id', $res->json('id'))->first()->status);

        $this->customer->paymentMethods()->delete();
        $this->addCard('4000000000000002');
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/shop/orders', $this->cart())->assertCreated()->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment.status', 'failed');
    }

    public function test_stores_only_see_and_act_on_their_own_orders(): void
    {
        $order = $this->place();
        $other = Store::query()->create(['name' => 'Rival', 'shop_category_id' => $this->store->shop_category_id, 'lat' => 13.1, 'lng' => -59.6]);
        $rivalOwner = $this->makeUser();
        $other->users()->attach($rivalOwner->id, ['role' => 'owner']);
        $this->actingAs($rivalOwner, 'web')->get("/store/{$other->slug}/orders/{$order->public_id}")->assertNotFound();
        $this->get(route('store.order-alerts', $this->store))->assertForbidden();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(OrderService::class)->accept($order, $other, 10);
    }

    public function test_admin_sees_orders_and_payments(): void
    {
        $order = $this->place();
        $this->actingAs($this->makeUser('admin'), 'web'); // API calls above switched the default guard to sanctum
        $this->get('/admin/orders')->assertOk()->assertSee($order->number());
        $this->get("/admin/orders/{$order->public_id}")->assertOk()->assertSee('Fried flying fish')->assertSee('Store receives');
        $this->get('/admin/payments')->assertOk()->assertSee('Order '.$order->number());
        $this->assertSame(0.15, ShopSettings::value('shop.commission_rate'));
    }
}
