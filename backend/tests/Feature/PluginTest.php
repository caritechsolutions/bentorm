<?php

namespace Tests\Feature;

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
}
