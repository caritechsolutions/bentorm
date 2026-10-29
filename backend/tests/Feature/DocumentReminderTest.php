<?php

namespace Tests\Feature;

use App\Jobs\SendPushJob;
use App\Models\DeviceToken;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Fleet;
use App\Models\Vehicle;
use App\Services\DocumentReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Reminders 30 and 7 days before a document expires and when it has expired — once each, to the right person. */
class DocumentReminderTest extends TestCase
{
    use RefreshDatabase;

    private function doc($owner, string $typeKey, string $expires, string $status = 'approved'): Document
    {
        return Document::query()->create(['documentable_type' => $owner->getMorphClass(), 'documentable_id' => $owner->id,
            'document_type_id' => DocumentType::query()->where('key', $typeKey)->value('id'), 'disk' => 'local', 'path' => 'x', 'expires_at' => $expires, 'status' => $status]);
    }

    public function test_reminders_go_out_once_at_30_and_7_days_and_on_expiry(): void
    {
        Queue::fake();
        $this->seedReference();
        $driver = $this->makeApprovedDriver();
        DeviceToken::query()->create(['user_id' => $driver->id, 'token' => 't1', 'platform' => 'android']);
        $licence = $this->doc($driver->driverProfile, 'drivers_licence', now()->addDays(25)->toDateString());
        $svc = app(DocumentReminderService::class);

        $this->assertSame(1, $svc->run());
        $this->assertSame(0, $svc->run(), 'once');
        Queue::assertPushed(SendPushJob::class, fn ($j) => str_contains(json_encode($j), 'Driver\'s licence expires on'));

        $this->assertSame(1, $svc->run(now()->addDays(19)), '6 days left');
        Queue::assertPushed(SendPushJob::class, fn ($j) => str_contains(json_encode($j), 'expires in 6 days'));
        $this->assertSame(0, $svc->run(now()->addDays(20)));

        $licence->forceFill(['status' => 'expired'])->save();
        $this->assertSame(1, $svc->run(now()->addDays(26)));
        Queue::assertPushed(SendPushJob::class, fn ($j) => str_contains(json_encode($j), 'expired on'));
        $this->assertSame(0, $svc->run(now()->addDays(27)));
    }

    public function test_no_reminder_when_a_new_copy_is_waiting_and_fleet_cars_remind_the_owner(): void
    {
        Queue::fake();
        $this->seedReference();
        $driver = $this->makeApprovedDriver();
        DeviceToken::query()->create(['user_id' => $driver->id, 'token' => 't1', 'platform' => 'android']);
        $this->doc($driver->driverProfile, 'drivers_licence', now()->addDays(5)->toDateString());
        $this->doc($driver->driverProfile, 'drivers_licence', now()->addYear()->toDateString(), 'pending'); // already uploaded
        $this->assertSame(0, app(DocumentReminderService::class)->run());

        $owner = $this->makeUser('rider');
        DeviceToken::query()->create(['user_id' => $owner->id, 'token' => 't2', 'platform' => 'android']);
        $fleet = Fleet::query()->create(['name' => 'Island Taxis']);
        $fleet->users()->attach($owner->id, ['role' => 'owner']);
        $car = Vehicle::query()->create(['fleet_id' => $fleet->id, 'make' => 'Toyota', 'model' => 'Axio', 'plate' => 'FL9', 'vehicle_type' => 'standard', 'status' => 'approved']);
        $this->doc($car, 'vehicle_insurance', now()->addDays(3)->toDateString());
        $this->assertSame(1, app(DocumentReminderService::class)->run());
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $owner->id && str_contains(json_encode($j), 'for FL9') && str_contains(json_encode($j), 'fleet portal'));
    }
}
