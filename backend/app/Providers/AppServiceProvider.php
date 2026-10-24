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
use Illuminate\Http\Resources\Json\JsonResource;
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
            'google' => new \App\Services\Routing\GoogleRoutesDistanceProvider($app->make(HaversineDistanceProvider::class)),
            default => $app->make(HaversineDistanceProvider::class),
        });
    }

    public function boot(): void
    {
        // Single resources are returned bare; paginated lists keep {data, links, meta}.
        JsonResource::withoutWrapping();

        // Push notifications for ride events (queued; nothing is sent until a Push gateway is configured).
        \App\Models\Trip::updated(fn (\App\Models\Trip $t) => app(\App\Observers\TripNotifications::class)->tripUpdated($t));
        \App\Models\TripOffer::created(fn (\App\Models\TripOffer $o) => app(\App\Observers\TripNotifications::class)->offerCreated($o));

        RateLimiter::for('otp-lookup', fn (Request $r) => [
            Limit::perMinute(10)->by('otp-lookup-phone:'.preg_replace('/\D+/', '', (string) $r->input('phone'))),
            Limit::perMinute(60)->by('otp-lookup-ip:'.$r->ip()),
        ]);
        RateLimiter::for('places', fn (Request $r) => Limit::perMinute(90)->by('places:'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        // Per phone number (the real abuse target) plus a looser per-IP cap — many users can share one IP.
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(6)->by('otp-phone:'.preg_replace('/\D+/', '', (string) $r->input('phone'))),
            Limit::perMinute(30)->by('otp-ip:'.$r->ip()),
        ]);
        RateLimiter::for('location', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}
