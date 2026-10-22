<?php

namespace Tests\Feature;

use App\Jobs\SendOtpEmailJob;
use App\Models\Gateway;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_new_rider_signs_up_with_phone_and_email_and_gets_the_code_on_both(): void
    {
        Queue::fake();
        Http::fake();

        // App asks first: unknown phone → it collects name + email before sending a code.
        $this->postJson('/api/v1/auth/otp/lookup', ['phone' => '246-555-1234'])
            ->assertOk()->assertJson(['phone' => '+12465551234', 'registered' => false, 'email_hint' => null]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '246-555-1234', 'email' => 'Rita@Example.com'])
            ->assertOk()->assertJsonPath('phone', '+12465551234')->assertJsonPath('code', '123456')
            ->assertJsonPath('channels', ['sms', 'email'])->assertJsonPath('email_hint', 'r•••@example.com');
        Queue::assertPushed(SendOtpEmailJob::class, fn ($job) => $job->email === 'rita@example.com' && $job->code === '123456');

        // Without a name → asked for it; the code is not used up by asking.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465551234', 'code' => '123456'])
            ->assertStatus(422)->assertJson(['needs_name' => true, 'needs_email' => false]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465551234'])->assertStatus(422); // cooldown

        $res = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465551234', 'code' => '123456', 'name' => 'Test Rider']);
        $res->assertCreated()->assertJsonPath('is_new', true)->assertJsonPath('user.role', 'rider')
            ->assertJsonPath('user.phone', '+12465551234')->assertJsonPath('user.email', 'rita@example.com');

        // ...but a successful login does use it up.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465551234', 'code' => '123456'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Code expired. Please request a new one.');

        $this->withToken($res->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('name', 'Test Rider');

        // Next time: registered, and the code goes to the email on file too.
        $this->travel(31)->seconds();
        $this->postJson('/api/v1/auth/otp/lookup', ['phone' => '2465551234'])->assertJson(['registered' => true, 'email_hint' => 'r•••@example.com']);
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465551234', 'email' => 'someone-else@example.com'])
            ->assertOk()->assertJsonPath('registered', true)->assertJsonPath('channels', ['sms', 'email']);
        Queue::assertPushed(SendOtpEmailJob::class, fn ($job) => $job->email === 'rita@example.com'); // never the one typed in
        Queue::assertNotPushed(SendOtpEmailJob::class, fn ($job) => $job->email === 'someone-else@example.com');
    }

    public function test_signup_needs_an_email_and_it_must_be_unused(): void
    {
        Queue::fake();
        $this->makeUser('rider', ['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465557777', 'email' => 'TAKEN@example.com'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'That email is already used by another account.');

        // Phone-only request (older app): verify then insists on an email.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465557777'])->assertOk()->assertJsonPath('channels', ['sms']);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465557777', 'code' => '123456', 'name' => 'No Email'])
            ->assertStatus(422)->assertJson(['needs_name' => false, 'needs_email' => true]);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465557777', 'code' => '123456', 'name' => 'No Email', 'email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465557777', 'code' => '123456', 'name' => 'Has Email', 'email' => 'new@example.com'])
            ->assertCreated()->assertJsonPath('user.email', 'new@example.com');
    }

    public function test_otp_email_job_sends_through_the_email_gateway(): void
    {
        Gateway::query()->where('type', 'email')->delete();
        Gateway::query()->create(['type' => 'email', 'plugin' => 'log_email', 'name' => 'Log', 'is_active' => true, 'priority' => 0, 'config' => []]);
        Log::spy();

        (new SendOtpEmailJob('rita@example.com', '123456', 5))->handle(app(PluginManager::class));

        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains($msg, 'rita@example.com') && str_contains($msg, '123456 is your Ride Coop code'));
    }

    public function test_sms_failure_still_succeeds_when_the_email_goes_out(): void
    {
        Queue::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'down'], 500)]);
        Gateway::query()->create(['type' => 'sms', 'plugin' => 'twilio', 'name' => 'Twilio', 'is_active' => true, 'priority' => 10,
            'config' => ['account_sid' => 'AC1', 'auth_token' => 't', 'from' => '+12465550000']]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465558888', 'email' => 'rita@example.com'])
            ->assertOk()->assertJsonPath('channels', ['email']);
        Queue::assertPushed(SendOtpEmailJob::class);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465550000'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465550000', 'code' => '000000', 'name' => 'X'])->assertStatus(422);
        $this->assertDatabaseMissing('users', ['phone' => '+12465550000']);
    }

    public function test_driver_registration_creates_profile(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465559999', 'email' => 'driver@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465559999', 'code' => '123456', 'name' => 'Driver', 'role' => 'driver'])
            ->assertCreated()->assertJsonPath('user.driver_profile.status', 'pending');

        $this->assertNotNull(User::query()->where('phone', '+12465559999')->first()?->driverProfile);
    }

    public function test_unauthenticated_api_returns_401_json(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->get('/api/v1/admin/stats')->assertUnauthorized();
    }
}
