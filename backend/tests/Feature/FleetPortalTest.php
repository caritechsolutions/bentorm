<?php

namespace Tests\Feature;

use App\Enums\FleetStatus;
use App\Enums\TripStatus;
use App\Filament\Fleet\Pages\EditFleet;
use App\Filament\Fleet\Pages\Overview;
use App\Filament\Fleet\Pages\RegisterFleet;
use App\Filament\Fleet\Pages\Statement;
use App\Filament\Fleet\Resources\Cars\Pages\ManageCars;
use App\Filament\Fleet\Resources\Drivers\Pages\ManageDrivers;
use App\Filament\Fleet\Resources\Team\Pages\ManageTeam;
use App\Filament\Fleet\Resources\Trips\Pages\ListTrips;
use App\Filament\Pages\Payouts;
use App\Filament\Resources\Fleets\Pages\ViewFleet;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Filament\Store\Pages\Auth\Register;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Fleet;
use App\Models\FleetDriver;
use App\Models\LedgerEntry;
use App\Models\ServiceArea;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverLocationService;
use App\Services\FleetService;
use App\Services\TripService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/** The fleet portal (/fleet) and the admin's Fleets section. */
class FleetPortalTest extends TestCase
{
    use RefreshDatabase;

    private ServiceArea $bb;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(['lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1], 'heading' => 90.0]);
        });
        $this->bb = $this->seedReference();
    }

    /** @return array{0: Fleet, 1: User} */
    private function fleetWithOwner(string $name = 'Island Taxis', bool $approved = true): array
    {
        $owner = $this->makeUser('rider');
        $owner->forceFill(['email' => strtolower(str_replace(' ', '', $name)).'@example.com', 'password' => Hash::make('Secret-pass-1')])->save();
        $fleet = Fleet::query()->create(['name' => $name, 'phone' => '2461234567', 'service_area_id' => $this->bb->id]);
        if ($approved) {
            $fleet->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        }
        $fleet->users()->attach($owner->id, ['role' => 'owner']);

        return [$fleet->fresh(), $owner];
    }

    private function asFleet(User $user, Fleet $fleet): void
    {
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('fleet'));
        Filament::setTenant($fleet);
        Filament::bootCurrentPanel();
    }

    private function car(Fleet $fleet, string $plate = 'FL1', string $status = 'approved'): Vehicle
    {
        return Vehicle::query()->create(['fleet_id' => $fleet->id, 'make' => 'Toyota', 'model' => 'Axio', 'plate' => $plate, 'vehicle_type' => 'standard', 'status' => $status]);
    }

    public function test_sign_up_then_register_the_fleet(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('fleet'));
        Livewire::test(Register::class)->assertSee('Create a login for your fleet')
            ->fillForm(['name' => 'Olive Owner', 'phone' => '246 555 7101', 'email' => 'olive@taxis.bb', 'password' => 'Secret-pass-1', 'passwordConfirmation' => 'Secret-pass-1'])
            ->call('register')->assertSet('codeSent', true)
            ->fillForm(['code' => '123456'])->call('register')->assertHasNoErrors();
        $user = User::query()->where('phone', '+12465557101')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->get('/fleet')->assertRedirect('/fleet/new');

        Livewire::test(RegisterFleet::class)
            ->fillForm(['name' => 'Olive\'s Taxis', 'service_area_id' => $this->bb->id, 'phone' => '2464281234'])
            ->call('register')->assertHasNoFormErrors();
        $fleet = Fleet::query()->firstOrFail();
        $this->assertSame(['olives-taxis', FleetStatus::Pending], [$fleet->slug, $fleet->status]);
        $this->assertTrue($user->isFleetOwner($fleet));
        $this->get("/fleet/{$fleet->slug}/overview")->assertOk()->assertSee('Waiting for approval')->assertSee('Add your cars');

        // Waiting for approval: cars can be added, drivers not invited yet.
        $this->asFleet($user, $fleet);
        Livewire::test(ManageDrivers::class)->assertActionHidden('invite');
    }

    public function test_each_login_only_sees_its_own_fleet(): void
    {
        [$a, $ownerA] = $this->fleetWithOwner('Island Taxis');
        [$b] = $this->fleetWithOwner('Bay Cabs');
        $this->car($a, 'AAA1');
        $this->car($b, 'BBB1');
        $this->actingAs($ownerA);
        $this->get("/fleet/{$a->slug}/cars")->assertOk()->assertSee('AAA1')->assertDontSee('BBB1');
        $this->get("/fleet/{$b->slug}/cars")->assertNotFound();
        $this->get(route('fleet.live', $b))->assertForbidden();
        $this->get(route('fleet.live', $a))->assertOk()->assertJsonPath('cars.0.plate', 'AAA1');
        // A store owner's login doesn't open fleets, and the other way round.
        $this->get('/store')->assertRedirect('/store/new');
    }

    public function test_cars_with_documents_and_edits_going_back_for_a_check(): void
    {
        [$fleet, $owner] = $this->fleetWithOwner();
        $this->asFleet($owner, $fleet);
        Livewire::test(ManageCars::class)
            ->callAction('create', ['plate' => 'pq 12-34', 'make' => 'Nissan', 'model' => 'Note', 'vehicle_type' => 'standard', 'seats' => 4, 'luggage_capacity' => 2])
            ->assertHasNoActionErrors();
        $car = Vehicle::query()->where('plate', 'PQ1234')->firstOrFail();
        $this->assertSame([$fleet->id, null, 'pending'], [$car->fleet_id, $car->driver_id, $car->status->value]);

        // A plate a driver already registered can't be added twice.
        $d = $this->makeApprovedDriver($this->bb);
        Livewire::test(ManageCars::class)
            ->callAction('create', ['plate' => 'Z'.$d->id, 'make' => 'Nissan', 'model' => 'Note', 'vehicle_type' => 'standard', 'seats' => 4, 'luggage_capacity' => 2])
            ->assertHasActionErrors(['plate']);

        $type = DocumentType::query()->where('applies_to', 'vehicle')->where('requires_expiry', true)->firstOrFail();
        Livewire::test(ManageCars::class)
            ->callTableAction('upload', $car, ['type' => $type->id, 'file' => UploadedFile::fake()->image('ins.jpg'), 'number' => 'N-1', 'expires_at' => now()->addYear()->toDateString()])
            ->assertHasNoTableActionErrors();
        $this->assertSame($car->id, Document::query()->where('documentable_type', Vehicle::class)->value('documentable_id'));

        $car->forceFill(['status' => 'approved'])->save();
        Livewire::test(ManageCars::class)->callTableAction('edit', $car, ['colour' => 'Silver'])->assertHasNoTableActionErrors();
        $this->assertSame(['Silver', 'pending'], [$car->fresh()->colour, $car->fresh()->status->value]);
    }

    public function test_invite_a_driver_choose_cars_and_remove_them(): void
    {
        [$fleet, $owner] = $this->fleetWithOwner();
        $c1 = $this->car($fleet, 'FL1');
        $c2 = $this->car($fleet, 'FL2');
        $d = $this->makeApprovedDriver($this->bb);
        $this->asFleet($owner, $fleet);
        Livewire::test(ManageDrivers::class)->callAction('invite', ['phone' => substr($d->phone, 2), 'name' => 'Mark', 'vehicles' => [$c1->id]])->assertHasNoActionErrors();
        Livewire::test(ManageDrivers::class)->callAction('invite', ['phone' => $d->phone])->assertHasActionErrors(['phone']);
        $fd = FleetDriver::query()->firstOrFail();
        $this->assertSame([$d->id, 'invited', [$c1->id]], [$fd->driver_id, $fd->status, $fd->vehicles->pluck('id')->all()]);

        app(FleetService::class)->respond($fd, $d, true);
        Livewire::test(ManageDrivers::class)->assertCanSeeTableRecords([$fd])->assertSee('FL1')
            ->callTableAction('cars', $fd, ['vehicles' => [$c1->id, $c2->id]])->assertHasNoTableActionErrors();
        $this->assertSame([$c1->id, $c2->id], $fd->fresh()->vehicles->pluck('id')->sort()->values()->all());
        Livewire::test(ManageDrivers::class)->callTableAction('remove', $fd)->assertHasNoTableActionErrors();
        $this->assertSame('removed', $fd->fresh()->status);
        $this->assertFalse(app(FleetService::class)->canUse($d, $c1));
    }

    public function test_overview_trips_and_statement_with_cash_handover(): void
    {
        [$fleet, $owner] = $this->fleetWithOwner();
        $car = $this->car($fleet);
        $d = $this->makeApprovedDriver($this->bb);
        $fd = app(FleetService::class)->invite($fleet, $d->phone, null, [$car->id], $owner);
        app(FleetService::class)->respond($fd, $d, true);
        $d->driverProfile->forceFill(['current_vehicle_id' => $car->id, 'is_online' => true])->save();

        $rider = $this->makeUser();
        $id = $this->actingAs($rider, 'sanctum')->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['payment_method' => 'cash']))->json('id');
        $trip = Trip::query()->where('public_id', $id)->first();
        $trip->forceFill(['driver_id' => $d->id, 'vehicle_id' => $car->id, 'fleet_id' => $fleet->id, 'status' => TripStatus::InProgress, 'started_at' => now()->subMinutes(10)])->save();
        $trip = app(TripService::class)->complete($trip->fresh(), $d->fresh());
        $other = $this->makeApprovedDriver($this->bb); // a trip in someone else's car
        Trip::query()->create(['rider_id' => $rider->id, 'driver_id' => $other->id, 'status' => 'completed', 'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'dropoff_lat' => 13.0, 'dropoff_lng' => -59.5,
            'pickup_address' => 'Somewhere else', 'vehicle_type' => 'standard', 'final_fare' => 99, 'driver_earnings' => 80, 'requested_at' => now()]);

        $this->asFleet($owner, $fleet);
        $this->get(route('fleet.live', $fleet))->assertJsonPath('cars.0.driver', $d->name)->assertJsonPath('cars.0.state', 'idle')
            ->assertJsonPath('cars.0.lat', self::BRIDGETOWN[0])->assertJsonPath('today.trips', 1);
        $this->get("/fleet/{$fleet->slug}/overview")->assertOk()->assertSee('Approved');
        Livewire::test(ListTrips::class)->assertCanSeeTableRecords([$trip])->assertCountTableRecords(1)->assertSee('FL1');

        $this->assertSame([$d->id => (float) $trip->final_fare], $fleet->cashHeld());
        Livewire::test(Statement::class)->assertSee($d->name)->assertSee(number_format($trip->final_fare, 2))
            ->callAction('received', ['amount' => $trip->final_fare, 'note' => 'Friday'], arguments: ['driver_id' => $d->id, 'name' => $d->name, 'held' => $trip->final_fare])
            ->assertHasNoActionErrors();
        $this->assertSame([$d->id => 0.0], $fleet->fresh()->cashHeld());

        // Managers run cars and drivers, but not money, settings or the team.
        $this->asFleet($owner, $fleet);
        Livewire::test(ManageTeam::class)->callAction('create', ['name' => 'Manny', 'phone' => '2465558101', 'role' => 'manager'])->assertHasNoActionErrors();
        $manager = User::query()->where('phone', '+12465558101')->firstOrFail();
        $this->actingAs($manager);
        $this->get("/fleet/{$fleet->slug}/drivers")->assertOk();
        $this->get("/fleet/{$fleet->slug}/statement")->assertForbidden();
        $this->get("/fleet/{$fleet->slug}/team")->assertForbidden();
        $this->get("/fleet/{$fleet->slug}/profile")->assertNotFound();

        // Bank details (owner), encrypted.
        $this->asFleet($owner, $fleet);
        Livewire::test(EditFleet::class)->fillForm(['bank_name' => 'Republic Bank', 'bank_account_name' => 'Island Taxis', 'bank_account_number' => '9876-5432'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertStringNotContainsString('9876', (string) \DB::table('fleets')->value('bank_account_number'));

        // Admin pays the fleet from Payouts → Fleets.
        $this->actingAs($this->makeUser('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/payouts?tab=fleet')->assertOk()->assertSee('Island Taxis')->assertSee('Republic Bank · Island Taxis · 9876-5432');
    }

    public function test_admin_approves_suspends_and_moves_a_drivers_car_into_a_fleet(): void
    {
        [$fleet] = $this->fleetWithOwner('Island Taxis', approved: false);
        $car = $this->car($fleet);
        $d = $this->makeApprovedDriver($this->bb); // their own car, online
        $own = Vehicle::query()->where('driver_id', $d->id)->firstOrFail();

        $this->actingAs($this->makeUser('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/fleets')->assertOk()->assertSee('Island Taxis');
        $this->get("/admin/fleets/{$fleet->id}")->assertOk()->assertSee('Waiting for approval');
        Livewire::test(\App\Filament\Resources\Fleets\RelationManagers\CarsRelationManager::class, ['ownerRecord' => $fleet, 'pageClass' => ViewFleet::class])
            ->assertCanSeeTableRecords([$car]);
        Livewire::test(ViewFleet::class, ['record' => $fleet->id])->callAction('approve')->assertHasNoActionErrors();
        Livewire::test(\App\Filament\Resources\Fleets\Pages\EditFleet::class, ['record' => $fleet->id])
            ->assertFormSet(['tips_to_driver' => true])->fillForm(['tips_to_driver' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($fleet->fresh()->tips_to_driver);
        $this->assertTrue($fleet->fresh()->isApproved());

        // The driver's own car is moved into the fleet; they keep driving it as a fleet driver, online throughout.
        Livewire::test(ViewVehicle::class, ['record' => $own->id])->callAction('moveToFleet', ['fleet_id' => $fleet->id, 'keep_driver' => true])->assertHasNoActionErrors();
        $own->refresh();
        $this->assertSame([$fleet->id, null], [$own->fleet_id, $own->driver_id]);
        $this->assertTrue(app(FleetService::class)->canUse($d, $own));
        $this->assertTrue($d->driverProfile->fresh()->is_online);

        // Suspending the fleet takes its drivers off the road.
        Livewire::test(ViewFleet::class, ['record' => $fleet->id])->callAction('suspend', ['note' => 'Insurance lapsed'])->assertHasNoActionErrors();
        $p = $d->driverProfile->fresh();
        $this->assertFalse($p->is_online);
        $this->assertStringContainsString('suspended', $p->offline_reason);
        $this->assertSame(0.0, LedgerEntry::balance('fleet', $fleet->id));
    }
}
