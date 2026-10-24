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

        // Promo codes held by a ride that didn't happen can be used again.
        \App\Models\Trip::updated(function (\App\Models\Trip $t) {
            if ($t->wasChanged('status') && in_array($t->status, [\App\Enums\TripStatus::Cancelled, \App\Enums\TripStatus::NoDrivers], true)) {
                app(\App\Services\PromoService::class)->releaseTrip($t);
            }
            // Driver payouts ledger: book finished rides, deliveries and cancellation fees.
            if ($t->wasChanged('status') && in_array($t->status, [\App\Enums\TripStatus::Completed, \App\Enums\TripStatus::Cancelled], true)) {
                app(\App\Services\LedgerService::class)->bookTrip($t);
            }
        });
        // Orders: release the code / return credit when one doesn't go ahead; mark used and reward invites on delivery.
        \App\Models\Order::updated(function (\App\Models\Order $o) {
            if (! $o->wasChanged('status')) {
                return;
            }
            if (in_array($o->status, [\App\Enums\OrderStatus::Cancelled, \App\Enums\OrderStatus::Declined, \App\Enums\OrderStatus::Expired], true)) {
                app(\App\Services\PromoService::class)->onOrderEnded($o);
            } elseif ($o->status === \App\Enums\OrderStatus::Delivered) {
                app(\App\Services\PromoService::class)->onOrderDelivered($o);
                app(\App\Services\LedgerService::class)->bookOrder($o);
                app(\App\Services\ReferralService::class)->onCompleted($o->customer);
            }
        });

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
