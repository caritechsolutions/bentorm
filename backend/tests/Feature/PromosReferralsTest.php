<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Models\CreditEntry;
use App\Models\DeviceToken;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\TripService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class PromosReferralsTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });
        $bb = $this->seedReference();
        $this->rider = $this->makeUser('rider');
        $this->driver = $this->makeApprovedDriver($bb);
    }

    /** Book → driver accepts/arrives/starts → completes; returns the finished trip. */
    private function ride(array $extra = []): Trip
    {
        $id = $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, $extra))->assertSuccessful()->json('id');
        $t = Trip::query()->where('public_id', $id)->first();
        $t->forceFill(['driver_id' => $this->driver->id, 'status' => TripStatus::InProgress, 'started_at' => now()->subMinutes(15), 'accepted_at' => now()->subMinutes(20)])->save();

        return app(TripService::class)->complete($t->fresh(), $this->driver);
    }

    public function test_promo_code_on_a_cash_ride_and_its_limits(): void
    {
        PromoCode::query()->create(['code' => 'welcome25', 'kind' => 'percent', 'value' => 25, 'max_discount' => 10, 'applies_to' => 'rides', 'max_uses_per_user' => 1]);
        $this->actingAs($this->rider, 'sanctum');
        $est = $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['promo_code' => 'WELCOME25']))->assertOk()->json('quotes.0');
        $this->assertEqualsWithDelta(min(10, $est['fare'] * 0.25), $est['discount'], 0.01);
        $this->postJson('/api/v1/promo-codes/check', ['code' => 'nope', 'for' => 'rides'])->assertUnprocessable()->assertJsonPath('errors.promo_code.0', 'That promo code isn\'t valid.');
        $this->postJson('/api/v1/promo-codes/check', ['code' => 'welcome25', 'for' => 'shop'])->assertUnprocessable()->assertJsonPath('errors.promo_code.0', 'That code is for rides.');

        $t = $this->ride(['promo_code' => 'welcome25']);
        $this->assertEqualsWithDelta(min(10, $t->final_fare * 0.25), $t->discount_amount, 0.01);
        $this->assertEqualsWithDelta($t->final_fare - $t->discount_amount, $t->payable(), 0.001);
        $this->assertEqualsWithDelta($t->payable(), (float) $t->payments()->where('method', 'cash')->value('amount'), 0.001, 'the driver collects the discounted fare');
        $this->assertGreaterThan(0, $t->driver_earnings, 'driver paid on the full fare');
        $this->assertSame('used', PromoRedemption::query()->value('status'));
        $this->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['promo_code' => 'welcome25']))->assertUnprocessable()->assertJsonPath('errors.promo_code.0', 'You\'ve already used this code.');

        // A cancelled booking gives the code back.
        PromoCode::query()->create(['code' => 'FLAT5', 'kind' => 'amount', 'value' => 5]);
        $id = $this->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['promo_code' => 'flat5']))->assertCreated()->json('id');
        $this->postJson("/api/v1/trips/{$id}/cancel")->assertOk();
        $this->assertSame('released', PromoRedemption::query()->latest('id')->value('status'));
        $this->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['promo_code' => 'flat5']))->assertCreated();

        Features::put('promos', false);
        $this->postJson('/api/v1/promo-codes/check', ['code' => 'flat5', 'for' => 'rides'])->assertForbidden();
    }

    public function test_referral_rewards_both_after_the_first_ride_and_credit_pays_part_of_a_card_ride(): void
    {
        $friend = $this->makeUser('rider', ['name' => 'Fred Friend']);
        DeviceToken::query()->create(['user_id' => $friend->id, 'token' => 'f', 'platform' => 'android']);
        $code = $this->actingAs($friend, 'sanctum')->getJson('/api/v1/me/referral')->assertOk()->json('code');
        $this->assertStringStartsWith('FRED', $code);
        $this->postJson('/api/v1/me/referral', ['code' => $code])->assertUnprocessable()->assertJsonPath('errors.code.0', 'That\'s your own code — share it with friends instead.');

        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/referral')->assertJsonPath('can_enter_code', true);
        $this->postJson('/api/v1/me/referral', ['code' => strtolower($code)])->assertOk();
        $this->postJson('/api/v1/me/referral', ['code' => $code])->assertUnprocessable();
        $this->assertSame(0.0, CreditEntry::balance($this->rider->id));

        $this->ride();
        $this->assertSame([10.0, 10.0], [CreditEntry::balance($this->rider->id), CreditEntry::balance($friend->id)]);
        $this->ride();
        $this->assertSame(10.0, CreditEntry::balance($friend->id), 'only the first ride counts');

        // Credit on a card ride: the card pays the rest.
        $card = $this->addTestCard($this->rider);
        $t = $this->ride(['payment_method' => 'card', 'payment_method_id' => $card, 'use_credit' => true]);
        $this->assertSame(10.0, $t->credit_amount);
        $this->assertSame(0.0, CreditEntry::balance($this->rider->id));
        $this->assertEqualsWithDelta($t->final_fare - 10, (float) $t->hold()->captured_amount, 0.001);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/credit')->assertJsonPath('entries.0.label', 'Used on a ride')
            ->assertJsonPath('entries.1.label', 'Invite reward');
    }
}
