<?php

namespace App\Providers;

use App\Contracts\DistanceProvider;
use App\Contracts\SmsSender;
use App\Services\Routing\HaversineDistanceProvider;
use App\Services\Routing\OsrmDistanceProvider;
use App\Plugins\PluginManager;
use App\Services\PluginSmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginManager::class);
        $this->app->bind(SmsSender::class, PluginSmsSender::class);

        $this->app->bind(DistanceProvider::class, fn ($app) => match (config('pricing.distance_provider')) {
            'osrm' => new OsrmDistanceProvider($app->make(HaversineDistanceProvider::class)),
            default => $app->make(HaversineDistanceProvider::class),
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('otp', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('location', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}
