<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Jobs\SendPushJob;
use App\Models\DeviceToken;
use App\Models\HelpArticle;
use App\Models\SupportTicket;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use RefreshDatabase;

    /** Signs a user in to the in-app web pages the way the apps do. */
    private function openApp($user, string $page): \Illuminate\Testing\TestResponse
    {
        $url = $this->actingAs($user, 'sanctum')->postJson('/api/v1/app-links', ['page' => $page])->json('url');
        $this->app['auth']->forgetGuards();

        return $this->followingRedirects()->get(parse_url($url, PHP_URL_PATH));
    }

    public function test_help_pages_lost_item_ticket_and_staff_reply(): void
    {
        Queue::fake();
        $bb = $this->seedReference();
        $this->assertGreaterThan(5, HelpArticle::query()->count(), 'starter help pages');
        $rider = $this->makeUser('rider', ['name' => 'Rita Rider']);
        $driver = $this->makeApprovedDriver($bb);
        DeviceToken::query()->create(['user_id' => $driver->id, 'token' => 'd', 'platform' => 'android']);
        DeviceToken::query()->create(['user_id' => $rider->id, 'token' => 'r', 'platform' => 'android']);
        $trip = Trip::query()->create(['rider_id' => $rider->id, 'driver_id' => $driver->id, 'service_area_id' => $bb->id, 'vehicle_type' => 'standard',
            'status' => TripStatus::Completed, 'pickup_lat' => 13.1, 'pickup_lng' => -59.6, 'dropoff_lat' => 13.07, 'dropoff_lng' => -59.53, 'dropoff_address' => 'Oistins',
            'currency' => 'BBD', 'requested_at' => now()->subHour(), 'completed_at' => now()]);

        $this->openApp($rider, 'help')->assertOk()->assertSee('What is my ride PIN?')->assertDontSee('Taking deliveries as well as rides');
        $this->get('/app/help?q=umbrella')->assertOk()->assertSee('Nothing found');
        $this->get('/app/help?q=SOS')->assertOk()->assertSee('What does the SOS button do?')->assertDontSee('Ordering from a store');
        $this->get('/app/help/'.HelpArticle::query()->where('title', 'What is my ride PIN?')->value('slug'))->assertOk()->assertSee('<strong>PIN</strong>', false);
        $this->get('/app/support/new?category=lost_item&trip='.$trip->public_id)->assertOk()->assertSee('I lost something')->assertSee('Oistins');

        $this->post('/app/support', ['category' => 'lost_item', 'trip' => $trip->public_id, 'body' => 'Black umbrella, back seat'])->assertRedirect();
        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame(['lost_item', $trip->id, 'open'], [$ticket->category, $ticket->trip_id, $ticket->status]);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $driver->id && $j->message->title === 'Lost item report');
        $this->get("/app/support/tickets/{$ticket->id}")->assertOk()->assertSee('Black umbrella, back seat');

        // Staff reply from the admin panel → push → the customer reads and answers in the app.
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'web');
        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->id])->callAction('reply', ['body' => 'Found it — the driver will drop it off.'])->assertHasNoActionErrors();
        $this->assertSame('answered', $ticket->fresh()->status);
        Queue::assertPushed(SendPushJob::class, fn ($j) => $j->userId === $rider->id && $j->message->data['page'] === "support/tickets/{$ticket->id}");
        $this->get('/admin/support-tickets')->assertOk();
        $this->get('/admin/help-articles')->assertOk();

        $this->openApp($rider, "support/tickets/{$ticket->id}")->assertSee('Found it');
        $this->post("/app/support/tickets/{$ticket->id}", ['body' => 'Thank you!'])->assertRedirect();
        $this->assertSame(['open', 3], [$ticket->fresh()->status, $ticket->messages()->count()]);

        // Someone else's ticket is not visible.
        $other = $this->makeUser('rider', ['name' => 'Otto Other']);
        $this->openApp($other, 'about')->assertSee('Otto Other');
        $this->get("/app/support/tickets/{$ticket->id}")->assertNotFound();
    }
}
