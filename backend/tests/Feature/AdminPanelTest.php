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
        ]);

        $this->actingAs($admin);
        foreach ([
            '/admin', '/admin/live-map', '/admin/drivers', "/admin/drivers/{$profile->id}", "/admin/drivers/{$profile->id}/edit",
            '/admin/documents', '/admin/vehicles', "/admin/vehicles/{$vehicle->id}", "/admin/vehicles/{$vehicle->id}/edit",
            '/admin/trips', "/admin/trips/{$trip->public_id}", '/admin/payments', '/admin/users', "/admin/users/{$admin->id}/edit",
            '/admin/service-areas', "/admin/service-areas/{$bb->id}/edit", '/admin/service-areas/create',
            '/admin/vehicle-types', '/admin/document-types', '/admin/gateways', '/admin/gateways/create', '/admin/gateways/1/edit',
        ] as $url) {
            $this->get($url)->assertOk();
        }

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
}
