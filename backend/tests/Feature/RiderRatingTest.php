<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Services\DriverLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/** Drivers rate riders after a ride; drivers see the rider's average; riders never see how they were rated. */
class RiderRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_rates_the_rider_and_the_average_shows_to_drivers_only(): void
    {
        Queue::fake();
        $this->mock(DriverLocationService::class, fn (MockInterface $m) => $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update', 'position')->andReturnNull());
        $this->seedReference();
        $rider = $this->makeUser();
        $driver = $this->makeApprovedDriver();
        $id = $this->actingAs($rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['payment_method' => 'cash']))->json('id');
        $trip = Trip::query()->where('public_id', $id)->first();

        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/driver/trips/{$id}/rate", ['score' => 4])->assertStatus(403, 'not their trip yet');
        $trip->forceFill(['driver_id' => $driver->id, 'status' => TripStatus::Completed, 'completed_at' => now(), 'final_fare' => 20])->save();

        $this->postJson("/api/v1/driver/trips/{$id}/rate", ['score' => 4, 'comment' => 'Kept us waiting'])->assertCreated();
        $this->assertSame(['4.00', 1], [$rider->fresh()->rider_rating_avg, $rider->fresh()->rider_rating_count]);
        $this->getJson("/api/v1/driver/trips/{$id}")->assertJsonPath('rider_rating.avg', 4.0)->assertJsonPath('rated_by_me', true);
        $this->assertSame('5.00', (string) $driver->driverProfile->fresh()->rating_avg, 'the driver\'s own rating is untouched');

        // The rider: hasn't rated yet, and can't see the driver's rating of them.
        $snap = $this->actingAs($rider, 'sanctum')->getJson("/api/v1/trips/{$id}")->assertOk();
        $this->assertFalse($snap->json('trip.rated_by_me'));
        $this->assertSame([], $snap->json('trip.ratings') ?? []);
        $this->assertNull($snap->json('trip.rider_rating'));
        $this->postJson("/api/v1/trips/{$id}/rate", ['score' => 5])->assertCreated();
        $this->assertSame('5.00', (string) $driver->driverProfile->fresh()->rating_avg);
        $this->assertSame('4.00', (string) $rider->fresh()->rider_rating_avg, 'rider average only from drivers');
    }
}
