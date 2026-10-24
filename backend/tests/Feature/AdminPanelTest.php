<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login_and_non_admins_are_refused(): void
    {
        $this->seedReference();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Phone number');

        $this->actingAs($this->makeUser('rider'))->get('/admin')->assertForbidden();
        $this->actingAs($this->makeUser('driver'))->get('/admin')->assertForbidden();
        $this->actingAs($this->makeUser('admin', ['is_active' => false]))->get('/admin')->assertForbidden();
    }

    public function test_admin_can_log_in_with_phone_and_password(): void
    {
        $this->seedReference();
        $admin = $this->makeUser('admin');
        $admin->forceFill(['password' => 'Secret-pass-123'])->save();

        \Livewire\Livewire::test(\App\Filament\Pages\Auth\Login::class)
            ->fillForm(['phone' => preg_replace('/^\+1/', '', $admin->phone), 'password' => 'Secret-pass-123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_every_admin_screen_renders(): void
    {
        Storage::fake('local');
        $bb = $this->seedReference();
        $admin = $this->makeUser('admin');
        $driver = $this->makeApprovedDriver($bb);
        $profile = $driver->driverProfile;
        $vehicle = $profile->currentVehicle;
        $doc = $profile->documents()->create([
            'document_type_id' => DocumentType::query()->where('key', 'driver_photo')->value('id'),
            'disk' => 'local', 'path' => UploadedFile::fake()->image('p.jpg')->store('documents', 'local'), 'mime' => 'image/jpeg', 'status' => 'pending',
        ]);
        $trip = Trip::query()->create([
            'rider_id' => $this->makeUser()->id, 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'service_area_id' => $bb->id,
            'status' => 'completed', 'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'dropoff_lat' => 13.07, 'dropoff_lng' => -59.53,
            'final_fare' => 30.5, 'fare_breakdown' => ['lines' => [['code' => 'base', 'label' => 'Base fare', 'amount' => 5.0]]],
            'stops' => [['lat' => 13.079, 'lng' => -59.608, 'address' => 'Garrison Savannah', 'reached_at' => now()->toIso8601String()]],
            'stop_change' => ['status' => 'accepted', 'stops' => [], 'estimated_fare' => 31.0, 'requested_at' => now()->toIso8601String()],
            'pin' => '4821', 'pin_verified_at' => now(), 'pin_attempts' => 1,
        ]);

        $this->actingAs($admin);
        foreach ([
            '/admin', '/admin/live-map', '/admin/drivers', "/admin/drivers/{$profile->id}", "/admin/drivers/{$profile->id}/edit",
            '/admin/documents', '/admin/vehicles', "/admin/vehicles/{$vehicle->id}", "/admin/vehicles/{$vehicle->id}/edit",
            '/admin/trips', "/admin/trips/{$trip->public_id}", '/admin/payments', '/admin/users', "/admin/users/{$admin->id}/edit",
            '/admin/service-areas', "/admin/service-areas/{$bb->id}/edit", '/admin/service-areas/create',
            '/admin/vehicle-types', '/admin/document-types', '/admin/gateways', '/admin/gateways/create', '/admin/gateways/'.\App\Models\Gateway::query()->value('id').'/edit',
            '/admin/navigation-settings', '/admin/dispatch-settings', '/admin/dispatch-settings/create', '/admin/dispatch-settings/'.\App\Models\DispatchSetting::query()->whereNull('service_area_id')->value('id').'/edit',
            '/admin/app-features', '/admin/promo-codes',
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get("/admin/trips/{$trip->public_id}")->assertSee('Garrison Savannah')->assertSee('Accepted by the driver')->assertSee('1 wrong attempt(s)');

        $this->get(route('admin.document.file', $doc))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->getJson(route('admin.live'))->assertOk()->assertJsonStructure(['drivers', 'trips']);
    }

    public function test_admin_files_and_live_data_are_admin_only(): void
    {
        Storage::fake('local');
        $bb = $this->seedReference();
        $driver = $this->makeApprovedDriver($bb);
        $doc = $driver->driverProfile->documents()->create([
            'document_type_id' => DocumentType::query()->value('id'), 'disk' => 'local', 'path' => 'x.pdf', 'status' => 'pending',
        ]);
        $this->actingAs($driver)->get(route('admin.document.file', $doc))->assertForbidden();
        $this->actingAs($driver)->get(route('admin.live'))->assertForbidden();
    }

    public function test_admin_makes_a_promo_code_and_gives_credit(): void
    {
        $this->seedReference();
        $admin = $this->makeUser('admin');
        $rider = $this->makeUser('rider');
        $this->actingAs($admin, 'web');
        \Livewire\Livewire::test(\App\Filament\Resources\PromoCodes\Pages\ManagePromoCodes::class)
            ->callAction('create', ['code' => 'summer15', 'kind' => 'percent', 'value' => 15, 'applies_to' => 'both', 'min_spend' => 0, 'max_uses_per_user' => 1, 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('SUMMER15', \App\Models\PromoCode::query()->value('code'));
        \Livewire\Livewire::test(\App\Filament\Resources\Users\Pages\ListUsers::class)
            ->callTableAction('credit', $rider, ['amount' => 12.5, 'note' => 'Sorry about the wait'])->assertHasNoTableActionErrors()
            ->callTableAction('credit', $rider, ['amount' => -20, 'note' => 'Too much'])->assertHasNoTableActionErrors();
        $this->assertSame(12.5, \App\Models\CreditEntry::balance($rider->id), 'cannot go below zero');
        $this->actingAs($rider, 'sanctum')->getJson('/api/v1/me/credit')->assertJsonPath('entries.0.label', 'Sorry about the wait');
    }
}
