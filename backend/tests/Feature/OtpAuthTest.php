<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_new_rider_can_register_with_otp(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '246-555-1234'])
            ->assertOk()->assertJsonPath('phone', '+12465551234')->assertJsonPath('code', '123456');

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465551234', 'code' => '123456'])
            ->assertStatus(422)->assertJsonPath('needs_name', true);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465551234'])->assertStatus(422); // cooldown

        $this->travel(31)->seconds();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465551234'])->assertOk();
        $res = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465551234', 'code' => '123456', 'name' => 'Test Rider']);
        $res->assertCreated()->assertJsonPath('is_new', true)->assertJsonPath('user.role', 'rider')
            ->assertJsonPath('user.phone', '+12465551234');

        $this->withToken($res->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('name', 'Test Rider');
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465550000'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '2465550000', 'code' => '000000', 'name' => 'X'])->assertStatus(422);
        $this->assertDatabaseMissing('users', ['phone' => '+12465550000']);
    }

    public function test_driver_registration_creates_profile(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '2465559999'])->assertOk();
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
