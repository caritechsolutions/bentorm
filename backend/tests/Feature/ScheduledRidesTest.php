<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Jobs\DispatchTripJob;
use App\Jobs\SendPushJob;
use App\Models\DeviceToken;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\TripService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ScheduledRidesTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });
        $this->seedReference();
        $this->rider = $this->makeUser('rider');
        DeviceToken::query()->create(['user_id' => $this->rider->id, 'token' => 'r', 'platform' => 'android']);
    }

    private function book(array $extra = [], string $in = '+2 hours'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS,
            $extra + ['scheduled_at' => now()->modify($in)->toIso8601String(), 'pickup_address' => 'Bay Street']));
    }

    public function test_book_for_later_then_dispatch_starts_before_pickup(): void
    {
        $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['scheduled_at' => now()->addHours(2)->toIso8601String()]))->assertOk();
        $this->book([], '+10 minutes')->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->book([], '+9 days')->assertUnprocessable()->assertJsonPath('errors.scheduled_at.0', 'Rides can be booked up to 7 days ahead.');

        $res = $this->book()->assertCreated()->assertJsonPath('status', 'scheduled')->assertJsonMissingPath('pin');
        $trip = Trip::query()->where('public_id', $res->json('id'))->firstOrFail();
        Queue::assertNotPushed(DispatchTripJob::class);
        $this->assertNull($this->rider->activeTrip(), 'a booking is not an active ride');
        $this->getJson('/api/v1/trips/active')->assertJsonPath('trip', null);
        $this->getJson('/api/v1/trips/scheduled')->assertOk()->assertJsonPath('0.id', $trip->public_id)->assertJsonPath('0.scheduled_at', $trip->scheduled_at->toIso8601String());

        // Can still take a ride now meanwhile.
        $this->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS))->assertCreated();
        Trip::query()->where('status', 'requested')->update(['status' => 'cancelled']);

        $this->assertSame(0, app(TripService::class)->startDueScheduled(), 'not yet');
        $this->travel(106)->minutes();
        $this->assertSame(1, app(TripService::class)->startDueScheduled());
        $trip->refresh();
        $this->assertSame(TripStatus::Requested, $trip->status);
        $this->assertNotNull($trip->requested_at);
        Queue::assertPushed(DispatchTripJob::class);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $this->rider->id && $j->message->title === 'Finding your driver');
        $active = $this->actingAs($this->rider->fresh(), 'sanctum')->getJson('/api/v1/trips/active')->json();
        $this->assertSame($trip->public_id, $active['trip']['id'] ?? json_encode($active));
    }

    public function test_card_booking_holds_the_card_at_dispatch_time_and_cancel_is_free(): void
    {
        $card = $this->addTestCard($this->rider);
        $id = $this->book(['payment_method' => 'card', 'payment_method_id' => $card])->assertCreated()->json('id');
        $trip = Trip::query()->where('public_id', $id)->first();
        $this->assertNull($trip->hold(), 'no hold until near pickup');

        $this->travel(106)->minutes();
        app(TripService::class)->startDueScheduled();
        $trip->refresh();
        $this->assertSame([TripStatus::Requested, 'authorized'], [$trip->status, $trip->hold()->status]);

        // Cancelling a booking before it starts costs nothing.
        $id2 = $this->book([], '+1 day')->json('id');
        $this->postJson("/api/v1/trips/{$id2}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertEquals(0, Trip::query()->where('public_id', $id2)->value('cancellation_fee'));
    }

    public function test_limits_switch_off_and_missed_bookings(): void
    {
        $this->book()->assertCreated();
        $this->book([], '+3 hours')->assertCreated();
        $missed = $this->book([], '+4 hours')->assertCreated()->json('id');
        $this->book([], '+5 hours')->assertUnprocessable()->assertJsonPath('errors.scheduled_at.0', 'You already have 3 rides booked. Cancel one to book another.');

        Trip::query()->where('public_id', $missed)->update(['scheduled_at' => now()->subHour()]);
        app(TripService::class)->startDueScheduled();
        $m = Trip::query()->where('public_id', $missed)->first();
        $this->assertSame([TripStatus::Cancelled, 'Booking missed'], [$m->status, $m->cancellation_reason]);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->message->title === 'Booked ride cancelled');

        Features::put('scheduled_rides', false);
        $this->book([], '+6 hours')->assertForbidden();
    }
}
