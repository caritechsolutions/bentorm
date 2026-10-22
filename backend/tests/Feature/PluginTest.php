<?php

namespace Tests\Feature;

use App\Plugins\PluginManager;
use App\Enums\GatewayType;
use App\Models\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PluginTest extends TestCase
{
    use RefreshDatabase;

    public function test_plugins_listed_with_config_schema(): void
    {
        $this->seedReference();
        $res = $this->actingAs($this->makeUser('admin'))->getJson('/api/v1/admin/plugins')->assertOk();
        $keys = collect($res->json('plugins'))->pluck('key');
        foreach (['log_sms', 'http_sms', 'twilio', 'log_email', 'smtp', 'test_payment'] as $k) {
            $this->assertTrue($keys->contains($k), "{$k} registered");
        }
    }

    public function test_secrets_are_encrypted_masked_and_preserved_on_update(): void
    {
        $this->seedReference();
        $admin = $this->makeUser('admin');

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/gateways', [
            'plugin' => 'twilio', 'name' => 'Twilio', 'is_active' => true, 'priority' => 10,
            'config' => ['account_sid' => 'AC123', 'auth_token' => 'super-secret', 'from' => '+12465550000'],
        ])->assertCreated()->assertJsonPath('config.auth_token', '••••••••')->json('id');

        $raw = \DB::table('gateways')->where('id', $id)->value('config');
        $this->assertStringNotContainsString('super-secret', $raw, 'encrypted at rest');

        $this->actingAs($admin)->patchJson("/api/v1/admin/gateways/{$id}", ['config' => ['account_sid' => 'AC999', 'auth_token' => '••••••••', 'from' => '+12465550000']])->assertOk();
        $this->assertSame('super-secret', Gateway::query()->find($id)->config['auth_token']);
        $this->assertSame('AC999', Gateway::query()->find($id)->config['account_sid']);

        $this->actingAs($admin)->postJson('/api/v1/admin/gateways', ['plugin' => 'twilio', 'name' => 'Bad', 'config' => ['from' => 'x']])
            ->assertStatus(422)->assertJsonValidationErrors(['config.account_sid', 'config.auth_token']);
    }

    public function test_otp_goes_through_active_sms_gateway(): void
    {
        $this->seedReference();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        Gateway::query()->create(['type' => 'sms', 'plugin' => 'twilio', 'name' => 'Twilio', 'is_active' => true, 'priority' => 10,
            'config' => ['account_sid' => 'AC1', 'auth_token' => 't', 'from' => '+12465550000']]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '12465551234'])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'Accounts/AC1/Messages.json') && $req['To'] === '+12465551234'
            && $req['Body'] === 'Your Ride Coop code is 123456. It expires in 5 minutes.'
            && $req->header('Authorization') === ['Basic '.base64_encode('AC1:t')]);
    }

    public function test_failed_sms_is_a_clear_503_and_can_be_retried_at_once(): void
    {
        $this->seedReference();
        Http::fake(['api.twilio.com/*' => Http::sequence()
            ->push(['code' => 21211, 'message' => "The 'To' number is not a valid phone number."], 400)
            ->push(['sid' => 'SM2'], 201)]);
        Gateway::query()->create(['type' => 'sms', 'plugin' => 'twilio', 'name' => 'Twilio', 'is_active' => true, 'priority' => 10,
            'config' => ['account_sid' => 'AC1', 'auth_token' => 't', 'from' => '+12465550000']]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '12465551234'])
            ->assertStatus(503)->assertJsonPath('message', "We couldn't send the text message right now. Please try again in a minute.");
        $this->assertDatabaseMissing('otp_codes', ['phone' => '+12465551234']);

        // No 30 s cooldown after a failed send: the rider can try again straight away.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '12465551234'])->assertOk();
    }

    public function test_gateway_test_endpoint_records_result(): void
    {
        $this->seedReference();
        $g = Gateway::query()->where('plugin', 'log_sms')->first();
        $this->actingAs($this->makeUser('admin'))->postJson("/api/v1/admin/gateways/{$g->id}/test", ['to' => '+12465550000'])->assertOk()->assertJsonPath('ok', true);
        $this->assertStringStartsWith('OK:', $g->fresh()->last_test_result);
    }

    public function test_gateway_command_creates_updates_and_keeps_secrets(): void
    {
        $this->seedReference();
        $this->artisan('bentorm:gateway', ['plugin' => 'smtp', '--name' => 'Email (SMTP)', '--set' => [
            'host=smtp.ipower.com', 'port=465', 'encryption=ssl', 'username=secure@ridecoop.net', 'password=@W@!ditw!k234567', 'from_address=secure@ridecoop.net',
        ]])->expectsOutputToContain('Created email gateway')->assertSuccessful();

        $g = Gateway::query()->where('plugin', 'smtp')->sole();
        $this->assertSame('@W@!ditw!k234567', $g->config['password']);
        $this->assertSame(465, $g->config['port']);
        $this->assertStringNotContainsString('ipower', (string) \DB::table('gateways')->where('id', $g->id)->value('config'), 'stored encrypted');
        $this->assertSame($g->id, app(PluginManager::class)->gatewayFor(GatewayType::Email)?->id, 'beats the dev log gateway');

        // Re-run without the password: updated in place, password kept.
        $this->artisan('bentorm:gateway', ['plugin' => 'smtp', '--name' => 'Email (SMTP)', '--set' => ['host=mail.example.com']])
            ->expectsOutputToContain('Updated email gateway')->assertSuccessful();
        $g->refresh();
        $this->assertSame(['mail.example.com', '@W@!ditw!k234567'], [$g->config['host'], $g->config['password']]);
        $this->assertSame(1, Gateway::query()->where('plugin', 'smtp')->count());

        $this->artisan('bentorm:gateway', ['plugin' => 'smtp', '--name' => 'Other', '--set' => ['port=465']])
            ->expectsOutputToContain('SMTP host is required')->assertFailed();
        $this->artisan('bentorm:gateway', ['plugin' => 'nope'])->assertFailed();
    }

    public function test_gateway_command_test_failure_is_recorded_and_fails(): void
    {
        $this->seedReference();
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Authenticate'], 401)]);
        $this->artisan('bentorm:gateway', ['plugin' => 'twilio', '--set' => ['account_sid=AC1', 'auth_token=bad', 'from=+12465550000'], '--test' => true])
            ->expectsOutputToContain('Test: FAIL: Twilio 401: Authenticate')->assertFailed();
        $this->assertStringStartsWith('FAIL', Gateway::query()->where('plugin', 'twilio')->value('last_test_result'));
    }
}
