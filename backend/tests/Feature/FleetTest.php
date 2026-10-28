<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Models\DocumentType;
use App\Models\Fleet;
use App\Models\FleetCash;
use App\Models\FleetDriver;
use App\Models\LedgerEntry;
use App\Models\ServiceArea;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverLocationService;
use App\Services\FleetService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/** Fleets on the driver side: invitations, fleet cars, one driver per car, money to the fleet, taking a car away. */
class FleetTest extends TestCase
{
    use RefreshDatabase;

    private ServiceArea $bb;

    private Fleet $fleet;

    private User $owner;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });
        $this->bb = $this->seedReference();
        DocumentType::query()->update(['is_required' => false]); // documents have their own tests
        $this->owner = $this->makeUser('rider', ['name' => 'Olive Owner']);
        $this->fleet = Fleet::query()->create(['name' => 'Island Taxis', 'phone' => '+12465550999', 'service_area_id' => $this->bb->id]);
        $this->fleet->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        $this->fleet->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->car = Vehicle::query()->create(['fleet_id' => $this->fleet->id, 'make' => 'Toyota', 'model' => 'Axio', 'plate' => 'FL1', 'vehicle_type' => 'standard', 'status' => 'approved']);
    }

    private function joinedDriver(string $name = 'Mark'): User
    {
        $d = $this->makeApprovedDriver($this->bb);
        $d->forceFill(['name' => $name])->save();
        $d->driverProfile->forceFill(['is_online' => false])->save();
        $invite = app(FleetService::class)->invite($this->fleet, $d->phone, null, [$this->car->id], $this->owner);
        $this->actingAs($d, 'sanctum')->postJson("/api/v1/driver/fleet-invites/{$invite->id}/accept")->assertOk();

        return $d->fresh();
    }

    private function ride(User $driver, string $method = 'cash'): Trip
    {
        $rider = $this->makeUser();
        $extra = ['payment_method' => $method];
        if ($method === 'card') {
            $extra['payment_method_id'] = $this->addTestCard($rider);
        }
        $id = $this->actingAs($rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, $extra))->assertSuccessful()->json('id');
        $t = Trip::query()->where('public_id', $id)->first();
        $t->forceFill(['driver_id' => $driver->id, 'vehicle_id' => $driver->driverProfile->current_vehicle_id, 'fleet_id' => $this->car->fleet_id,
            'status' => TripStatus::InProgress, 'started_at' => now()->subMinutes(15), 'accepted_at' => now()->subMinutes(20)])->save();

        return $t->fresh();
    }

    public function test_invited_driver_accepts_sees_the_fleet_car_and_drives_it(): void
    {
        $d = $this->makeApprovedDriver($this->bb);
        $d->driverProfile->forceFill(['is_online' => false])->save();
        $invite = app(FleetService::class)->invite($this->fleet, $d->phone, 'Mark', [$this->car->id], $this->owner);

        $profile = $this->actingAs($d, 'sanctum')->getJson('/api/v1/driver/profile')->assertOk();
        $profile->assertJsonPath('fleet_invites.0.fleet.name', 'Island Taxis')->assertJsonPath('fleet_invites.0.vehicles.0.plate', 'FL1');
        $this->getJson('/api/v1/driver/vehicles')->assertJsonCount(1); // not until accepted
        $this->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertForbidden();

        $this->postJson("/api/v1/driver/fleet-invites/{$invite->id}/accept")->assertOk()->assertJsonPath('status', 'active');
        $this->getJson('/api/v1/driver/fleet-invites')->assertJsonPath('invites', []);
        $cars = $this->getJson('/api/v1/driver/vehicles')->assertOk()->json();
        $this->assertSame(['Z'.$d->id, 'FL1'], array_column($cars, 'plate'), 'own car first, then fleet cars');
        $this->assertSame('Island Taxis', $cars[1]['fleet']['name']);
        $this->assertNull($cars[1]['in_use_by']);

        $this->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertOk()->assertJsonPath('current_vehicle.fleet.name', 'Island Taxis');
        $this->postJson('/api/v1/driver/online')->assertOk();
        $this->assertTrue($d->driverProfile->fresh()->is_online);

        // Driver documents stay the driver's; the fleet's car documents are the fleet's.
        $this->getJson("/api/v1/driver/vehicles/{$this->car->id}/documents")->assertForbidden();
        $this->patchJson("/api/v1/driver/vehicles/{$this->car->id}", ['colour' => 'Red'])->assertForbidden();
    }

    public function test_an_invite_by_phone_waits_for_the_driver_to_sign_up_and_can_be_declined(): void
    {
        $invite = app(FleetService::class)->invite($this->fleet, '246 555 7777', 'New Guy', [$this->car->id], $this->owner);
        $this->assertNull($invite->driver_id);
        $d = $this->makeUser('driver', ['phone' => '+12465557777']);
        $this->actingAs($d, 'sanctum')->getJson('/api/v1/driver/fleet-invites')->assertJsonPath('invites.0.id', $invite->id);
        $this->postJson("/api/v1/driver/fleet-invites/{$invite->id}/decline")->assertOk()->assertJsonPath('status', 'declined');
        $this->assertSame($d->id, $invite->fresh()->driver_id);

        // Someone else can't answer it.
        $again = app(FleetService::class)->invite($this->fleet, '+12465557777', null, [], $this->owner);
        $this->actingAs($this->makeUser('driver'), 'sanctum')->postJson("/api/v1/driver/fleet-invites/{$again->id}/accept")->assertNotFound();
    }

    public function test_one_driver_per_car(): void
    {
        $mark = $this->joinedDriver('Mark');
        $jane = $this->joinedDriver('Jane');
        $this->actingAs($mark, 'sanctum')->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertOk();
        $this->postJson('/api/v1/driver/online')->assertOk();

        $this->actingAs($jane, 'sanctum');
        $this->assertSame('Mark', collect($this->getJson('/api/v1/driver/vehicles')->json())->firstWhere('plate', 'FL1')['in_use_by']);
        $this->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'in use by Mark'));

        // Mark goes offline: Jane can take it; then Mark can't go online in it.
        $this->actingAs($mark, 'sanctum')->postJson('/api/v1/driver/offline')->assertOk();
        $this->actingAs($jane, 'sanctum')->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertOk();
        $this->postJson('/api/v1/driver/online')->assertOk();
        $this->actingAs($mark, 'sanctum')->postJson('/api/v1/driver/online')->assertForbidden()->assertJsonPath('issues', fn ($i) => in_array('FL1 is in use by Jane.', $i, true));
    }

    public function test_the_fleet_is_paid_and_cash_is_tracked_per_driver(): void
    {
        $mark = $this->joinedDriver('Mark');
        $this->actingAs($mark, 'sanctum')->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertOk();
        $mark = $mark->fresh();

        $card = app(TripService::class)->complete($this->ride($mark, 'card'), $mark);
        $cash = app(TripService::class)->complete($this->ride($mark), $mark);
        $commission = round($cash->driver_earnings - $cash->final_fare, 2);

        $this->assertEqualsWithDelta($card->driver_earnings + $commission, LedgerEntry::balance('fleet', $this->fleet->id), 0.001);
        $this->assertSame(0.0, LedgerEntry::balance('driver', $mark->id), 'nothing to the driver');
        $this->assertStringContainsString('FL1, Mark', LedgerEntry::query()->where('payee_type', 'fleet')->value('description'));
        $this->assertSame([$mark->id => (float) $cash->final_fare], $this->fleet->cashHeld(), 'Mark holds the cash for the owner');

        app(FleetService::class)->recordCashReceived($this->fleet, $mark->id, (float) $cash->final_fare, 'Friday', $this->owner);
        $this->assertSame([$mark->id => 0.0], $this->fleet->cashHeld());
        $this->assertSame(2, FleetCash::query()->count());

        // The driver's app: earnings are shown as paid to the fleet.
        $e = $this->actingAs($mark, 'sanctum')->getJson('/api/v1/driver/earnings')->assertOk();
        $e->assertJsonPath('periods.today.trips', 0)->assertJsonPath('fleets.0.fleet', 'Island Taxis')->assertJsonPath('fleets.0.periods.today.trips', 2);
        $this->getJson("/api/v1/driver/trips/{$card->public_id}")->assertJsonPath('earnings_paid_to', 'Island Taxis');
    }

    public function test_tips_go_to_the_driver_unless_the_fleet_keeps_them(): void
    {
        $mark = $this->joinedDriver('Mark');
        $this->actingAs($mark, 'sanctum')->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertOk();
        $mark = $mark->fresh();
        $this->assertTrue($this->fleet->fresh()->tips_to_driver, 'default: the driver gets the tips');

        $card = app(TripService::class)->complete($this->ride($mark, 'card'), $mark);
        $this->actingAs($card->rider, 'sanctum')->postJson("/api/v1/trips/{$card->public_id}/tip", ['amount' => 5])->assertOk();
        $this->assertSame(5.0, LedgerEntry::balance('driver', $mark->id), 'tip to Mark');
        $this->assertEqualsWithDelta($card->driver_earnings, LedgerEntry::balance('fleet', $this->fleet->id), 0.001, 'fare to the fleet');
        $e = $this->actingAs($mark, 'sanctum')->getJson('/api/v1/driver/earnings')->assertOk();
        $e->assertJsonPath('periods.today.tips', 5.0)->assertJsonPath('periods.today.earnings', 5.0)
            ->assertJsonPath('fleets.0.periods.today.earnings', (float) $card->driver_earnings);

        // Admin switches this fleet to keep tips: the next tip goes to the fleet.
        $this->fleet->forceFill(['tips_to_driver' => false])->save();
        $card2 = app(TripService::class)->complete($this->ride($mark, 'card'), $mark);
        $this->actingAs($card2->rider, 'sanctum')->postJson("/api/v1/trips/{$card2->public_id}/tip", ['amount' => 3])->assertOk();
        $this->assertSame(5.0, LedgerEntry::balance('driver', $mark->id));
        $this->assertEqualsWithDelta($card->driver_earnings + $card2->driver_earnings + 3, LedgerEntry::balance('fleet', $this->fleet->id), 0.001);
    }

    public function test_taking_the_car_away_mid_trip_lets_the_trip_finish_then_sets_the_driver_offline(): void
    {
        $mark = $this->joinedDriver('Mark');
        $this->actingAs($mark, 'sanctum')->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertOk();
        $this->postJson('/api/v1/driver/online')->assertOk();
        $mark = $mark->fresh();
        $trip = $this->ride($mark);

        $fd = FleetDriver::query()->where('driver_id', $mark->id)->first();
        app(FleetService::class)->remove($fd);
        $this->assertTrue($mark->driverProfile->fresh()->is_online, 'still on the trip');
        $this->actingAs($mark, 'sanctum')->postJson('/api/v1/driver/location', ['lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]])->assertJsonPath('online', true);

        app(TripService::class)->complete($trip, $mark);
        $p = $mark->driverProfile->fresh();
        $this->assertFalse($p->is_online);
        $this->assertNull($p->current_vehicle_id);
        $this->postJson('/api/v1/driver/location', ['lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]])
            ->assertJsonPath('online', false)->assertJsonPath('offline_reason', 'Island Taxis removed you from their fleet.');
        $this->getJson('/api/v1/driver/vehicles')->assertJsonMissing(['plate' => 'FL1']);
        $this->assertEqualsWithDelta($trip->fresh()->driver_earnings - $trip->fresh()->final_fare, LedgerEntry::balance('fleet', $this->fleet->id), 0.001, 'the trip still earned for the fleet');

        // Choosing their own car clears the message and they can go online.
        $own = $mark->vehicles()->first();
        $this->actingAs($mark->fresh(), 'sanctum')->postJson("/api/v1/driver/vehicles/{$own->id}/select")->assertOk()->assertJsonPath('offline_reason', null);
        $this->actingAs($mark->fresh(), 'sanctum')->postJson('/api/v1/driver/online')->assertOk();
    }

    public function test_taking_the_car_away_while_idle_sets_the_driver_offline_at_once(): void
    {
        $mark = $this->joinedDriver('Mark');
        $this->actingAs($mark, 'sanctum')->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertOk();
        $this->postJson('/api/v1/driver/online')->assertOk();
        $fd = FleetDriver::query()->where('driver_id', $mark->id)->first();
        app(FleetService::class)->setVehicles($fd, []);
        $this->assertFalse($mark->driverProfile->fresh()->is_online);
        $this->assertSame('Island Taxis took away access to this car.', $mark->driverProfile->fresh()->offline_reason);

        // A suspended fleet's cars can't be used either.
        app(FleetService::class)->setVehicles($fd->fresh(), [$this->car->id]);
        $this->fleet->forceFill(['status' => 'suspended'])->save();
        $this->postJson("/api/v1/driver/vehicles/{$this->car->id}/select")->assertForbidden();
    }
}
