<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Jobs\SendPushJob;
use App\Models\DeviceToken;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class TipsTest extends TestCase
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
        DeviceToken::query()->create(['user_id' => $this->driver->id, 'token' => 'd', 'platform' => 'android']);
    }

    private function completedTrip(string $method = 'cash'): Trip
    {
        $trip = Trip::query()->create([
            'rider_id' => $this->rider->id, 'driver_id' => $this->driver->id, 'service_area_id' => 1, 'vehicle_type' => 'standard',
            'status' => TripStatus::Completed, 'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'dropoff_lat' => 13.07, 'dropoff_lng' => -59.53,
            'currency' => 'BBD', 'final_fare' => 40, 'driver_earnings' => 30, 'payment_method' => $method, 'payment_status' => 'paid',
            'completed_at' => now(), 'requested_at' => now()->subHour(),
        ]);

        return $trip->fresh();
    }

    public function test_tip_a_finished_ride_by_card_once(): void
    {
        $card = $this->addTestCard($this->rider);
        $trip = $this->completedTrip();
        $this->actingAs($this->rider, 'sanctum')->getJson("/api/v1/trips/{$trip->public_id}")->assertJsonPath('trip.can_tip', true);
        $this->postJson("/api/v1/trips/{$trip->public_id}/tip", ['amount' => 500])->assertUnprocessable();
        $r = $this->postJson("/api/v1/trips/{$trip->public_id}/tip", ['amount' => 5])->assertOk()->assertJsonPath('can_tip', false);
        $this->assertEquals(5, $r->json('tip'));
        $this->assertSame(['tip', 'captured', 5.0], [($p = Payment::query()->latest('id')->first())->purpose, $p->status, (float) $p->captured_amount]);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $this->driver->id && str_contains($j->message->body, 'tipped you BBD 5.00'));
        $this->postJson("/api/v1/trips/{$trip->public_id}/tip", ['amount' => 5])->assertUnprocessable()->assertJsonPath('errors.amount.0', 'You already tipped for this trip. Thank you!');
        $e = $this->actingAs($this->driver, 'sanctum')->getJson('/api/v1/driver/earnings')->json('periods.today');
        $this->assertEquals([5, 35], [$e['tips'], $e['earnings']]);
        $this->assertNotNull($card);
    }

    public function test_no_tip_after_the_window_when_off_or_declined(): void
    {
        $this->addTestCard($this->rider);
        $old = $this->completedTrip();
        $old->forceFill(['completed_at' => now()->subHours(25)])->save();
        $this->actingAs($this->rider, 'sanctum')->postJson("/api/v1/trips/{$old->public_id}/tip", ['amount' => 5])->assertUnprocessable()->assertJsonPath('errors.amount.0', 'This trip can no longer be tipped.');
        $this->actingAs($this->makeUser(), 'sanctum')->postJson("/api/v1/trips/{$this->completedTrip()->public_id}/tip", ['amount' => 5])->assertNotFound();

        $declined = $this->makeUser();
        $this->rider = $declined;
        $this->addTestCard($declined, '4000000000000002');
        $t = $this->completedTrip();
        $this->actingAs($declined, 'sanctum')->postJson("/api/v1/trips/{$t->public_id}/tip", ['amount' => 5])->assertStatus(402);
        $this->assertSame(0.0, $t->fresh()->tip_amount);

        Features::put('tips', false);
        $this->postJson("/api/v1/trips/{$t->public_id}/tip", ['amount' => 5])->assertForbidden();
    }
}
