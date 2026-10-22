<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $bb = $this->seedReference();
        $this->admin = $this->makeUser('admin');
        $this->driver = $this->makeUser('driver');
        $this->driver->driverProfile()->create(['service_area_id' => $bb->id]);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('photo.jpg', 2400, 1800);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('doc.pdf', 200, 'application/pdf');
    }

    /** Upload every required driver document and approve it. */
    private function completeDriverDocs(): void
    {
        $types = DocumentType::query()->where('applies_to', 'driver')->where('is_required', true)->get();
        foreach ($types as $t) {
            $res = $this->actingAs($this->driver)->post('/api/v1/driver/documents', array_filter([
                'document_type' => $t->key,
                'file' => $t->is_image_only ? $this->photo() : $this->pdf(),
                'number' => $t->requires_number ? 'N-123' : null,
                'expires_at' => $t->requires_expiry ? now()->addYear()->toDateString() : null,
            ]), ['Accept' => 'application/json']);
            $res->assertCreated();
            $this->actingAs($this->admin)->postJson("/api/v1/admin/documents/{$res->json('id')}/review", ['decision' => 'approve'])->assertOk();
        }
    }

    public function test_checklist_lists_required_documents_including_area_specific(): void
    {
        $res = $this->actingAs($this->driver)->getJson('/api/v1/driver/documents')->assertOk();
        $keys = collect($res->json('checklist'))->pluck('type.key');
        $this->assertTrue($keys->contains('drivers_licence'));
        $this->assertTrue($keys->contains('psv_permit'), 'Barbados-specific type included for BB drivers');
        $this->assertSame('missing', collect($res->json('checklist'))->firstWhere('type.key', 'drivers_licence')['state']);
    }

    public function test_upload_validation_rules(): void
    {
        $this->actingAs($this->driver)->post('/api/v1/driver/documents', ['document_type' => 'drivers_licence', 'file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('number');

        $this->actingAs($this->driver)->post('/api/v1/driver/documents', ['document_type' => 'driver_photo', 'file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->actingAs($this->driver)->post('/api/v1/driver/documents', ['document_type' => 'vehicle_insurance', 'file' => $this->pdf(), 'expires_at' => now()->addYear()->toDateString()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('document_type');
    }

    public function test_large_photos_are_downscaled_and_stored_privately(): void
    {
        $res = $this->actingAs($this->driver)->post('/api/v1/driver/documents', ['document_type' => 'driver_photo', 'file' => $this->photo()], ['Accept' => 'application/json'])
            ->assertCreated();
        $doc = \App\Models\Document::query()->find($res->json('id'));
        Storage::disk('local')->assertExists($doc->path);
        if (extension_loaded('gd')) {
            [$w, $h] = getimagesize(Storage::disk('local')->path($doc->path));
            $this->assertLessThanOrEqual(1600, max($w, $h));
        }
    }

    public function test_driver_cannot_be_approved_until_documents_are_approved(): void
    {
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/drivers/{$this->driver->id}/status", ['status' => 'approved'])
            ->assertStatus(422)->assertJsonPath('message', 'Driver cannot be approved yet.');

        $this->completeDriverDocs();

        $this->actingAs($this->admin)->patchJson("/api/v1/admin/drivers/{$this->driver->id}/status", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('driver.driver_profile.status', 'approved');
    }

    public function test_reject_requires_note_and_reupload_supersedes(): void
    {
        $up = fn () => $this->actingAs($this->driver)->post('/api/v1/driver/documents', ['document_type' => 'police_certificate', 'file' => $this->pdf()], ['Accept' => 'application/json'])->json('id');
        $first = $up();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/documents/{$first}/review", ['decision' => 'reject'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/documents/{$first}/review", ['decision' => 'reject', 'note' => 'Blurry'])->assertOk()->assertJsonPath('status', 'rejected');

        $second = $up();
        $this->assertSame('superseded', \App\Models\Document::query()->find($first)->status->value);
        $row = collect($this->actingAs($this->driver)->getJson('/api/v1/driver/documents')->json('checklist'))->firstWhere('type.key', 'police_certificate');
        $this->assertSame('pending', $row['state']);
        $this->assertSame($second, $row['document']['id']);
    }

    public function test_expired_documents_block_going_online(): void
    {
        $this->completeDriverDocs();
        $this->driver->driverProfile->update(['status' => 'approved']);

        \App\Models\Document::query()->whereHas('type', fn ($q) => $q->where('key', 'drivers_licence'))->update(['expires_at' => now()->subDay()->toDateString()]);
        $this->artisan('documents:expire')->assertSuccessful();

        $issues = $this->actingAs($this->driver)->getJson('/api/v1/driver/profile')->assertOk()->json('issues');
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, "Driver's licence")));
    }

    public function test_file_access_rules(): void
    {
        $vehicle = Vehicle::query()->create(['driver_id' => $this->driver->id, 'make' => 'Kia', 'model' => 'Rio', 'plate' => 'X1', 'vehicle_type' => 'standard']);
        $front = $this->actingAs($this->driver)->post("/api/v1/driver/vehicles/{$vehicle->id}/documents", ['document_type' => 'vehicle_photo_front', 'file' => $this->photo()], ['Accept' => 'application/json'])->json('id');
        $reg = $this->actingAs($this->driver)->post("/api/v1/driver/vehicles/{$vehicle->id}/documents", ['document_type' => 'vehicle_registration', 'file' => $this->pdf(), 'number' => 'R1'], ['Accept' => 'application/json'])->json('id');

        $rider = $this->makeUser('rider');
        $this->actingAs($this->driver)->get("/api/v1/files/documents/{$reg}")->assertOk();
        $this->actingAs($this->admin)->get("/api/v1/files/documents/{$reg}")->assertOk();
        $this->actingAs($rider)->get("/api/v1/files/documents/{$front}")->assertForbidden();

        Trip::query()->create(['rider_id' => $rider->id, 'driver_id' => $this->driver->id, 'vehicle_id' => $vehicle->id, 'status' => 'accepted',
            'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'dropoff_lat' => 13.0, 'dropoff_lng' => -59.5]);
        $this->actingAs($rider)->get("/api/v1/files/documents/{$front}")->assertOk();
        $this->actingAs($rider)->get("/api/v1/files/documents/{$reg}")->assertForbidden();
    }
}
