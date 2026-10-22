<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $bb = $this->seedReference();
        $this->rider = $this->makeUser('rider');
        $this->trip = Trip::query()->create([
            'rider_id' => $this->rider->id, 'service_area_id' => $bb->id, 'status' => 'completed', 'payment_method' => 'card',
            'pickup_lat' => self::BRIDGETOWN[0], 'pickup_lng' => self::BRIDGETOWN[1], 'dropoff_lat' => self::OISTINS[0], 'dropoff_lng' => self::OISTINS[1],
            'currency' => 'BBD', 'final_fare' => 32.50, 'amount_due' => 32.50, 'payment_status' => 'pending', 'completed_at' => now(),
        ]);
    }

    public function test_outstanding_balance_blocks_new_requests(): void
    {
        $this->actingAs($this->rider)->getJson('/api/v1/me/outstanding')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS))
            ->assertStatus(422)->assertJsonValidationErrors('payment');
    }

    public function test_hosted_page_flow_settles_trip(): void
    {
        $res = $this->actingAs($this->rider)->postJson("/api/v1/trips/{$this->trip->public_id}/pay")->assertOk();
        $url = $res->json('redirect_url');
        $paymentId = $res->json('payment.id');
        $this->assertNotEmpty($url);

        // Paying twice resumes the same payment.
        $this->assertSame($paymentId, $this->actingAs($this->rider)->postJson("/api/v1/trips/{$this->trip->public_id}/pay")->json('payment.id'));

        $page = $this->get($url)->assertOk()->assertSee('TEST GATEWAY');
        preg_match('/action="([^"]+approve[^"]*)"/', $page->getContent(), $m);
        $this->post(html_entity_decode($m[1]))->assertRedirect();

        $this->assertSame('paid', $this->trip->fresh()->payment_status->value);
        $this->assertSame(0.0, $this->trip->fresh()->amount_due);
        $this->actingAs($this->rider)->getJson("/api/v1/payments/{$paymentId}")->assertOk()->assertJsonPath('status', 'captured');
        $this->get(route('payments.return', ['payment' => $paymentId]))->assertOk()->assertSee('Payment complete');
    }

    public function test_unsigned_checkout_is_rejected(): void
    {
        $id = $this->actingAs($this->rider)->postJson("/api/v1/trips/{$this->trip->public_id}/pay")->json('payment.id');
        $this->get("/api/v1/payments/test/{$id}")->assertForbidden();
    }

    public function test_decline_leaves_amount_owed(): void
    {
        Gateway::query()->where('plugin', 'test_payment')->first()->update(['config' => ['mode' => 'auto_decline']]);
        $this->actingAs($this->rider)->postJson("/api/v1/trips/{$this->trip->public_id}/pay")->assertStatus(402)->assertJsonPath('payment.status', 'failed');
        $this->assertSame(32.5, $this->trip->fresh()->amount_due);
    }

    public function test_card_unavailable_without_gateway(): void
    {
        $this->trip->update(['payment_status' => 'paid', 'amount_due' => 0]);
        Gateway::query()->where('type', 'payment')->update(['is_active' => false]);
        $this->actingAs($this->rider)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['payment_method' => 'card']))
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_admin_can_waive(): void
    {
        $this->actingAs($this->makeUser('admin'))->postJson("/api/v1/admin/trips/{$this->trip->public_id}/waive", ['reason' => 'Goodwill'])
            ->assertOk()->assertJsonPath('payment_status', 'waived');
        $this->actingAs($this->rider)->getJson('/api/v1/me/outstanding')->assertJsonCount(0, 'data');
    }
}
