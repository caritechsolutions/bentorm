<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Filament\Pages\LegalSettings;
use App\Models\LedgerEntry;
use App\Models\SavedPlace;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverLocationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/** Delete my account (apps and web page) and the Terms / Privacy pages the app stores require. */
class AccountAndLegalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(DriverLocationService::class, function (MockInterface $m) {
            $m->shouldReceive('setState', 'goOnline', 'goOffline', 'update')->andReturnNull();
            $m->shouldReceive('position')->andReturn(null);
        });
        $this->seedReference();
    }

    public function test_a_rider_deletes_their_account_in_the_app(): void
    {
        $rider = $this->makeUser('rider', ['email' => 'rita@example.com']);
        SavedPlace::query()->create(['user_id' => $rider->id, 'label' => 'Home', 'name' => 'Home', 'lat' => 13.1, 'lng' => -59.6]);
        $token = $rider->createToken('app')->plainTextToken;
        $phone = $rider->phone;

        // A ride under way: not yet.
        $id = $this->withToken($token)->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['payment_method' => 'cash']))->assertSuccessful()->json('id');
        $this->getJson('/api/v1/me/deletion')->assertJsonPath('blockers.0', 'Finish or cancel your current ride first.');
        $this->deleteJson('/api/v1/me', ['confirm' => true])->assertStatus(409);
        Trip::query()->where('public_id', $id)->update(['status' => TripStatus::Cancelled->value]);

        $this->deleteJson('/api/v1/me', [])->assertUnprocessable(); // must confirm
        $this->deleteJson('/api/v1/me', ['confirm' => true])->assertOk();

        $gone = User::withTrashed()->find($rider->id);
        $this->assertSame(['Deleted user', null, false], [$gone->name, $gone->email, $gone->is_active]);
        $this->assertNotSame($phone, $gone->phone);
        $this->assertSoftDeleted($gone);
        $this->assertSame(0, SavedPlace::query()->where('user_id', $rider->id)->count());
        $this->assertSame(1, DB::table('account_deletions')->where('user_id', $rider->id)->where('via', 'app')->count());
        $this->assertNotNull(Trip::query()->where('public_id', $id)->value('rider_id'), 'trip history kept, anonymous');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        // The number can sign up again as a new account.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();
    }

    public function test_a_driver_who_owes_cash_commission_must_settle_first(): void
    {
        $driver = $this->makeApprovedDriver();
        LedgerEntry::query()->create(['payee_type' => 'driver', 'payee_id' => $driver->id, 'kind' => 'ride_cash', 'amount' => -4.5, 'description' => 'Cash ride']);
        $this->actingAs($driver, 'sanctum')->getJson('/api/v1/me/deletion')
            ->assertJsonPath('blockers.0', 'Settle the BBD 4.50 you owe Ride Coop from cash rides first.');
    }

    public function test_the_public_page_deletes_an_account_with_a_code_to_the_phone(): void
    {
        $rider = $this->makeUser('rider');
        $this->get('/account/delete')->assertOk()->assertSee('Delete your Ride Coop account')->assertSee('What is kept');
        $this->post('/account/delete/code', ['phone' => substr($rider->phone, 2)])->assertRedirect('/account/delete');
        $this->get('/account/delete')->assertSee('Code from the text message');
        $this->post('/account/delete', ['code' => '000000', 'confirm' => '1'])->assertSessionHasErrors('code');
        $this->post('/account/delete', ['code' => '123456'])->assertSessionHasErrors('confirm');
        $this->post('/account/delete', ['code' => '123456', 'confirm' => '1'])->assertOk()->assertSee('Your account is deleted');
        $this->assertSoftDeleted(User::withTrashed()->find($rider->id));

        // An unknown number gets the same answer (no account fishing) and nothing is sent.
        $this->post('/account/delete/code', ['phone' => '2465550999'])->assertRedirect('/account/delete')->assertSessionHas('flash');
    }

    public function test_terms_and_privacy_are_public_and_edited_in_admin(): void
    {
        $this->get('/terms')->assertOk()->assertSee('Terms of Use')->assertSee('Draft for review')->assertSee(url('/account/delete'));
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy')->assertSee('Data Protection Act');
        $this->getJson('/api/v1/config')->assertJsonPath('links.privacy', url('/privacy'));

        $this->actingAs($this->makeUser('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/legal-pages')->assertOk();
        Livewire::test(LegalSettings::class)
            ->fillForm(['contact_email' => 'privacy@ridecoop.net', 'terms' => "# Terms of Use\n\nOur final terms. <script>alert(1)</script>"])
            ->call('save')->assertHasNoFormErrors();
        $this->get('/terms')->assertSee('Our final terms.')->assertDontSee('Draft for review')->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/privacy')->assertSee('privacy@ridecoop.net');
    }
}
