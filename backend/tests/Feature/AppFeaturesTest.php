<?php

namespace Tests\Feature;

use App\Filament\Pages\FeatureSettings;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_lists_feature_switches_and_admin_changes_them(): void
    {
        $f = $this->getJson('/api/v1/config')->assertOk()->json('features');
        $this->assertSame([true, 30], [$f['tips'], $f['scheduled.min_minutes']]);
        $this->actingAs($this->makeUser('admin'), 'web');
        Livewire::test(FeatureSettings::class)->set('data.tips', false)->set('data.tips__max_amount', 50)->call('save')->assertHasNoErrors();
        $f = $this->getJson('/api/v1/config')->json('features');
        $this->assertSame([false, 50], [$f['tips'], $f['tips.max_amount']]);
        $this->assertFalse(Features::on('tips'));
        $this->assertArrayNotHasKey('auto_surge', $this->getJson('/api/v1/config')->json('features'), 'admin-only switches stay private');
    }

    public function test_app_link_signs_in_once_and_opens_the_page(): void
    {
        $rider = $this->makeUser('rider', ['name' => 'Rita Rider']);
        $url = $this->actingAs($rider, 'sanctum')->postJson('/api/v1/app-links', ['page' => 'about'])->assertOk()->json('url');
        $this->postJson('/api/v1/app-links', ['page' => '../admin'])->assertUnprocessable();
        $path = parse_url($url, PHP_URL_PATH);

        $this->app['auth']->forgetGuards();
        $this->get($path)->assertRedirect('/app/about');
        $this->get('/app/about')->assertOk()->assertSee('Rita Rider');

        // Single use: a second visit (new session) is refused.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($path)->assertRedirect('/app/signed-out');
        $this->get('/app/about')->assertRedirect('/app/signed-out');
        $this->get('/app/signed-out')->assertOk()->assertSee('Open this from the app');
    }
}
