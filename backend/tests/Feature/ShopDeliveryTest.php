<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Filament\Store\Resources\Orders\Pages\ListOrders;
use App\Jobs\DispatchTripJob;
use App\Jobs\SendPushJob;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceArea;
use App\Models\ShopCategory;
use App\Models\Store;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DispatchService;
use App\Services\DriverLocationService;
use App\Services\OrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Shop phase 3: deliveries are trips (store → customer) offered to drivers who opted in, on the real Redis GEO
 * code (DB 15, test prefix; skipped without Redis). The store confirms the hand-over; the customer's PIN finishes it.
 */
class ShopDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private ServiceArea $bb;

    private Store $store;

    private User $owner;

    private User $customer;

    private Product $fish;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.default.database' => 15, 'database.redis.options.prefix' => 'bentorm_test:']);
        Redis::purge('default');
        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis not available');
        }
        Redis::connection()->flushdb();
        Queue::fake([SendPushJob::class, DispatchTripJob::class]);
        $this->bb = $this->seedReference();
        $this->owner = $this->makeUser('rider');
        $this->store = Store::query()->create(['name' => 'Fish Pot', 'shop_category_id' => ShopCategory::query()->where('slug', 'food')->value('id'),
            'phone' => '2464281234', 'address' => 'Oistins', 'lat' => self::OISTINS[0], 'lng' => self::OISTINS[1], 'prep_minutes' => 20]);
        $this->store->forceFill(['status' => 'approved', 'is_open' => true])->save();
        $this->store->refresh();
        $this->store->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->fish = $this->store->products()->create(['name' => 'Fried flying fish', 'price' => 30]);
        $this->customer = $this->makeUser('rider');
        DeviceToken::query()->create(['user_id' => $this->customer->id, 'token' => 'cust-phone', 'platform' => 'android']);
    }

    protected function tearDown(): void
    {
        try {
            Redis::connection()->flushdb();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    /** An online, idle driver near the store; $deliveries = opted in. $type = their vehicle's type. */
    private function driver(bool $deliveries, string $type = 'standard', float $km = 1): User
    {
        $d = $this->makeApprovedDriver($this->bb);
        Vehicle::query()->whereKey($d->driverProfile->current_vehicle_id)->update(['vehicle_type' => $type, 'colour' => 'Blue', 'plate' => 'PB'.$d->id]);
        $d->driverProfile->forceFill(['accepts_deliveries' => $deliveries])->save();
        $loc = app(DriverLocationService::class);
        $loc->goOnline($d, $type);
        $loc->update($d, self::OISTINS[0] + $km * 0.009, self::OISTINS[1], vehicleType: $type);
        DeviceToken::query()->create(['user_id' => $d->id, 'token' => 'drv-'.$d->id, 'platform' => 'android']);

        return $d->fresh();
    }

    private function acceptedOrder(int $prep = 30, array $extras = []): Order
    {
        $card = $this->card();
        $o = app(OrderService::class)->place($this->customer, $this->store, [['product_id' => $this->fish->id, 'quantity' => 1]],
            self::BRIDGETOWN[0], self::BRIDGETOWN[1], 'Bay Street', 'Blue gate', $card, $extras);

        return app(OrderService::class)->accept($o->fresh(), $this->store, $prep);
    }

    private function card(): string
    {
        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/me/payment-methods', ['lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]])->assertCreated();
        $form = $this->get($res->json('redirect_url'))->assertOk();
        preg_match('/action="([^"]+)"/', $form->getContent(), $m);
        $this->post(html_entity_decode($m[1]), ['number' => '4242424242424242', 'exp_month' => 12, 'exp_year' => now()->year + 2])->assertRedirect();
        $this->get(route('payment-methods.return', ['paymentMethod' => $res->json('payment_method.id')]))->assertOk();

        return $res->json('payment_method.id');
    }

    private function wave(Trip $trip): array
    {
        app(DispatchService::class)->runWave($trip->fresh());

        return $trip->offers()->pluck('driver_id')->all();
    }

    private function asStore(): void
    {
        $this->actingAs($this->owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('store'));
        Filament::setTenant($this->store);
        Filament::bootCurrentPanel();
    }

    private function pushedTo(int $userId, string $needle): bool
    {
        return Queue::pushed(SendPushJob::class, fn ($j) => $j->userId === $userId && str_contains($j->message->title.' '.$j->message->body, $needle))->isNotEmpty();
    }

    public function test_full_delivery_store_hands_over_and_the_customer_pin_finishes_it(): void
    {
        $no = $this->driver(false);
        $yes = $this->driver(true, 'xl', 2); // any vehicle type may deliver
        $order = $this->acceptedOrder(30);

        // Not yet: ready in 30 min, drivers go 10 min before.
        $this->assertSame(0, app(OrderService::class)->dispatchDue());
        $this->travel(21)->minutes();
        $this->assertSame(1, app(OrderService::class)->dispatchDue());
        $this->assertSame(0, app(OrderService::class)->dispatchDue(), 'one job at a time');
        $trip = $order->fresh()->activeDelivery();
        $this->assertSame(['delivery', 'requested', $order->delivery_fee, $order->fresh()->delivery_pin], [$trip->vehicle_type, $trip->status->value, (float) $trip->estimated_fare, $trip->pin]);
        $this->assertSame([$yes->id], $this->wave($trip), 'only the driver who opted in');
        $this->assertTrue($this->pushedTo($yes->id, 'New delivery'));

        // The driver sees what to collect, and what they earn — never the PIN.
        $offer = $this->actingAs($yes, 'sanctum')->getJson('/api/v1/driver/offers')->assertOk()->json('offers.0');
        $this->assertSame(['delivery', 'Fish Pot', 1, 'Fried flying fish', $order->driver_earnings], [$offer['trip']['kind'], $offer['trip']['delivery']['store']['name'],
            $offer['trip']['delivery']['items'][0]['quantity'], $offer['trip']['delivery']['items'][0]['name'], $offer['estimated_earnings']]);
        $this->assertArrayNotHasKey('pin', $offer['trip']);

        $this->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk()->assertJsonPath('delivery.order_number', $order->number());
        $this->assertTrue($this->pushedTo($this->customer->id, 'will collect your order'));
        $this->postJson("/api/v1/driver/trips/{$trip->public_id}/start", ['pin' => $order->fresh()->delivery_pin])->assertStatus(409);

        // Customer: driver and PIN; the delivery is not their "ride".
        $view = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/shop/orders/{$order->public_id}")->assertOk();
        $view->assertJsonPath('delivery.status', 'driver_assigned')->assertJsonPath('delivery.driver.name', $yes->firstName())
            ->assertJsonPath('delivery.driver.vehicle.plate', 'PB'.$yes->id)->assertJsonPath('delivery.pin', $order->fresh()->delivery_pin);
        $this->getJson('/api/v1/trips/active')->assertOk()->assertJsonPath('trip', null);
        $this->assertSame([], $this->getJson('/api/v1/trips')->json('data'));

        // Driver at the store; the store hands it over in the portal.
        $this->actingAs($yes, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/arrive")->assertOk();
        $this->asStore();
        Livewire::test(ListOrders::class)->set('activeTab', 'preparing')->assertSee($yes->firstName().' · Blue Toyota Corolla, PB'.$yes->id.' — at your store')
            ->callTableAction('pickedUp', $order)->assertHasNoTableActionErrors();
        $this->assertSame([OrderStatus::PickedUp, TripStatus::InProgress], [$order->fresh()->status, $trip->fresh()->status]);
        $this->assertTrue($this->pushedTo($this->customer->id, 'on the way'));

        // At the door: no PIN / wrong PIN refused; the right one completes and charges the card.
        $this->actingAs($yes, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/complete")->assertUnprocessable()
            ->assertJsonPath('errors.pin.0', 'Ask the customer for their 4-digit PIN.');
        $wrong = $order->fresh()->delivery_pin === '0000' ? '1111' : '0000';
        $this->postJson("/api/v1/driver/trips/{$trip->public_id}/complete", ['pin' => $wrong])->assertUnprocessable();
        $done = $this->postJson("/api/v1/driver/trips/{$trip->public_id}/complete", ['pin' => $order->fresh()->delivery_pin, 'lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]])->assertOk();
        $this->assertEquals([$order->driver_earnings, $order->delivery_fee], [$done->json('driver_earnings'), $done->json('actual.fare')]);
        $order->refresh();
        $this->assertSame([OrderStatus::Delivered, 'paid', 'captured'], [$order->status, $order->payment_status, $order->hold()->status]);
        $this->assertEqualsWithDelta($order->total, $order->hold()->captured_amount, 0.001);
        $this->assertTrue($this->pushedTo($this->customer->id, 'Delivered'));
        $this->assertSame('idle', Redis::hget("driver:{$yes->id}", 'state'));
        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/shop/orders/{$order->public_id}")->assertJsonPath('delivery.status', 'delivered')
            ->assertJsonMissingPath('delivery.pin');
        $this->assertNotContains($no->id, $trip->offers()->pluck('driver_id')->all());

        // Payouts ledger: the store's share and the driver's delivery earnings are booked; the store sees its statement.
        $this->assertEqualsWithDelta($order->store_payout, \App\Models\LedgerEntry::balance('store', $this->store->id), 0.001);
        $this->assertEqualsWithDelta($order->driver_earnings, \App\Models\LedgerEntry::balance('driver', $yes->id), 0.001);
        $this->asStore();
        $this->get("/store/{$this->store->slug}/statement")->assertOk()->assertSee('Order '.$order->number());
    }

    public function test_need_driver_now_and_ready_send_at_once_without_duplicates(): void
    {
        $this->driver(true);
        $order = $this->acceptedOrder(60);
        $this->asStore();
        Livewire::test(ListOrders::class)->set('activeTab', 'preparing')->assertSee('A driver is sent at')->callTableAction('needDriver', $order)->assertHasNoTableActionErrors();
        $this->assertSame(1, $order->deliveries()->count());
        Livewire::test(ListOrders::class)->set('activeTab', 'preparing')->assertTableActionHidden('needDriver', $order)->assertSee('Looking for a driver');
        app(OrderService::class)->markReady($order->fresh(), $this->store);
        app(OrderService::class)->dispatchDue();
        $this->assertSame(1, $order->deliveries()->count(), 'still the same job');
    }

    public function test_nobody_takes_it_retries_then_alerts_and_the_customer_may_cancel(): void
    {
        $admin = $this->makeUser('admin');
        foreach ([$admin, $this->owner] as $u) {
            DeviceToken::query()->create(['user_id' => $u->id, 'token' => 'tok-'.$u->id, 'platform' => 'android']);
        }
        $order = $this->acceptedOrder(5);
        app(OrderService::class)->dispatchDue();
        $first = $order->fresh()->activeDelivery();

        // That job ran out of waves: a fresh one goes out next minute.
        $first->forceFill(['status' => TripStatus::NoDrivers])->save();
        $this->travel(1)->minutes();
        app(OrderService::class)->dispatchDue();
        $this->assertSame(2, $order->deliveries()->count());
        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/shop/orders/{$order->public_id}")
            ->assertJsonPath('delivery.status', 'searching')->assertJsonPath('delivery.can_cancel', false);
        $this->postJson("/api/v1/shop/orders/{$order->public_id}/cancel")->assertUnprocessable();

        // 10 minutes without a driver: store, admins and customer are told once; the customer may now cancel.
        $this->travel(10)->minutes();
        app(OrderService::class)->dispatchDue();
        app(OrderService::class)->dispatchDue();
        $this->assertNotNull($order->fresh()->driver_alert_at);
        foreach ([$this->owner->id, $admin->id] as $uid) {
            $this->assertTrue($this->pushedTo($uid, 'No driver yet for order '.$order->number()));
        }
        $this->assertCount(1, Queue::pushed(SendPushJob::class, fn ($j) => $j->userId === $this->customer->id && str_contains($j->message->title, 'Still looking')));
        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/shop/orders/{$order->public_id}")
            ->assertJsonPath('delivery.no_driver_alert', true)->assertJsonPath('delivery.can_cancel', true);
        $this->postJson("/api/v1/shop/orders/{$order->public_id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(['voided', TripStatus::Cancelled], [$order->hold()->status, $order->deliveries()->latest('id')->first()->status]);
    }

    public function test_driver_cancels_a_new_job_goes_out_and_a_store_decline_calls_off_the_driver(): void
    {
        $a = $this->driver(true);
        $order = $this->acceptedOrder(5);
        app(OrderService::class)->dispatchDue();
        $trip = $order->fresh()->activeDelivery();
        $this->wave($trip);
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/cancel", ['reason' => 'Flat tyre'])->assertOk();
        $this->assertFalse($this->pushedTo($this->customer->id, 'Ride cancelled'), 'customers hear about orders, not rides');

        app(OrderService::class)->dispatchDue();
        $second = $order->fresh()->activeDelivery();
        $this->assertNotSame($trip->id, $second->id);
        $b = $this->driver(true, 'standard', 2);
        $this->assertContains($b->id, $this->wave($second));
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/driver/trips/{$second->public_id}/accept")->assertOk();

        app(OrderService::class)->decline($order->fresh(), $this->store, 'Kitchen fire');
        $this->assertSame(TripStatus::Cancelled, $second->fresh()->status);
        $this->assertTrue($this->pushedTo($b->id, 'Delivery cancelled'));
        $this->assertSame('idle', Redis::hget("driver:{$b->id}", 'state'));
    }

    public function test_drivers_switch_deliveries_on_in_the_app(): void
    {
        $d = $this->driver(false);
        $this->actingAs($d, 'sanctum')->patchJson('/api/v1/driver/profile', ['accepts_deliveries' => true])->assertOk()->assertJsonPath('accepts_deliveries', true);
        $this->assertTrue($d->driverProfile->fresh()->accepts_deliveries);
    }

    public function test_tips_at_checkout_and_after_delivery_go_to_the_driver(): void
    {
        $d = $this->driver(true);
        $this->actingAs($this->customer, 'sanctum');
        $q = $this->postJson('/api/v1/shop/quote', ['store' => $this->store->slug, 'lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1],
            'items' => [['product_id' => $this->fish->id, 'quantity' => 1]], 'tip' => 4])->assertOk();
        $this->assertEquals(4, $q->json('totals.tip'));
        $this->assertContains('Tip for your driver', array_column($q->json('lines'), 'label'));

        $order = $this->acceptedOrder(5, ['tip' => 4]);
        $this->assertEqualsWithDelta($order->total, $order->hold()->authorized_amount, 0.001, 'the tip is in the hold');
        app(OrderService::class)->dispatchDue();
        $trip = $order->fresh()->activeDelivery();
        $this->wave($trip);
        $this->actingAs($d, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/accept")->assertOk();
        app(OrderService::class)->pickedUp($order->fresh(), $this->store);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/shop/orders/{$order->public_id}/tip", ['amount' => 3])->assertStatus(409);
        $this->actingAs($d, 'sanctum')->postJson("/api/v1/driver/trips/{$trip->public_id}/complete", ['pin' => $order->fresh()->delivery_pin])->assertOk();
        $this->assertSame(4.0, $trip->fresh()->tip_amount);

        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/shop/orders/{$order->public_id}")->assertJsonPath('can_tip', true);
        $this->postJson("/api/v1/shop/orders/{$order->public_id}/tip", ['amount' => 3])->assertOk()->assertJsonPath('can_tip', false);
        $this->assertSame([7.0, 7.0], [$trip->fresh()->tip_amount, $order->fresh()->tip]);
        $this->assertTrue($this->pushedTo($d->id, 'tipped you BBD 3.00'));
    }
}
