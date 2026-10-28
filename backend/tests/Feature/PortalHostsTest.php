<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Store\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Each hostname opens only its own portal, admins can't sign in to the store/fleet portals, and everyone can change their password. */
class PortalHostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bentorm.admin_host' => 'newadmin.ridecoop.net', 'bentorm.fleet_host' => 'fleet.ridecoop.net', 'bentorm.store_host' => null,
            'bentorm.support_host' => 'support.ridecoop.net', 'app.url' => 'https://api.ridecoop.net']);
    }

    public function test_each_hostname_opens_only_its_own_portal(): void
    {
        $this->get('https://fleet.ridecoop.net/admin/vehicles/3?x=1')->assertRedirect('https://newadmin.ridecoop.net/admin/vehicles/3?x=1');
        $this->get('https://fleet.ridecoop.net/admin/login')->assertRedirect('https://newadmin.ridecoop.net/admin/login');
        $this->get('https://newadmin.ridecoop.net/fleet/login')->assertRedirect('https://fleet.ridecoop.net/fleet/login');
        $this->get('https://api.ridecoop.net/admin')->assertRedirect('https://newadmin.ridecoop.net/admin');
        $this->get('https://support.ridecoop.net/fleet/login')->assertRedirect('https://fleet.ridecoop.net/fleet/login');
        // No store hostname: the store portal stays on the admin host.
        $this->get('https://fleet.ridecoop.net/store/login')->assertRedirect('https://newadmin.ridecoop.net/store/login');
        $this->get('https://newadmin.ridecoop.net/store/login')->assertOk();

        $this->get('https://fleet.ridecoop.net/fleet/login')->assertOk();
        $this->get('https://newadmin.ridecoop.net/admin/login')->assertOk();
        $this->get('https://fleet.ridecoop.net/')->assertRedirect('/fleet');
        // The server's own address (LAN) still opens everything.
        $this->get('http://192.168.110.104:8082/admin/login')->assertOk();
        $this->get('http://192.168.110.104:8082/fleet/login')->assertOk();
        // The API is never redirected.
        $this->get('https://api.ridecoop.net/api/v1/health')->assertOk();
    }

    public function test_an_admin_login_on_the_fleet_portal_is_refused_with_a_clear_message(): void
    {
        $admin = $this->makeUser('admin', ['password' => Hash::make('Admin-pass-1')]);
        Filament::setCurrentPanel(Filament::getPanel('fleet'));
        Livewire::test(Login::class)->fillForm(['login' => $admin->phone, 'password' => 'Admin-pass-1'])->call('authenticate')
            ->assertHasErrors(['data.login'])->assertSee('This is an admin account');
        $this->assertGuest();
        // Without the right password nothing gives away that the number is an admin's.
        Livewire::test(Login::class)->fillForm(['login' => $admin->phone, 'password' => 'wrong'])->call('authenticate')
            ->assertHasErrors(['data.login'])->assertDontSee('admin account');
    }

    public function test_anyone_changes_their_password_on_my_profile(): void
    {
        foreach (['admin' => $this->makeUser('admin'), 'fleet' => $this->makeUser('rider')] as $panel => $user) {
            $user->forceFill(['password' => Hash::make('Old-pass-123')])->save();
            if ($panel === 'fleet') {
                \App\Models\Fleet::query()->create(['name' => 'Island Taxis'])->users()->attach($user->id, ['role' => 'owner']);
            }
            $this->flushSession(); // a fresh browser for each person
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel($panel));
            $this->get("/{$panel}/profile")->assertOk()->assertSee('Mobile number (your login)');
            Livewire::test(EditProfile::class)
                ->fillForm(['password' => 'New-pass-456', 'passwordConfirmation' => 'New-pass-456', 'currentPassword' => 'wrong'])
                ->call('save')->assertHasErrors(['data.currentPassword']);
            Livewire::test(EditProfile::class)
                ->fillForm(['password' => 'New-pass-456', 'passwordConfirmation' => 'New-pass-456', 'currentPassword' => 'Old-pass-123'])
                ->call('save')->assertHasNoErrors();
            $this->assertTrue(Hash::check('New-pass-456', $user->fresh()->password), $panel);
        }
    }
}
