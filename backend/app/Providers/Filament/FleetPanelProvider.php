<?php

namespace App\Providers\Filament;

use App\Filament\Fleet\Pages\EditFleet;
use App\Filament\Fleet\Pages\RegisterFleet;
use App\Filament\Store\Pages\Auth\Login;
use App\Filament\Store\Pages\Auth\Register;
use App\Filament\Store\Pages\Auth\ResetPassword;
use App\Models\Fleet;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The fleet portal (/fleet): taxi owners run their cars and the drivers who drive them. Same logins as the store
 * portal (phone or email + password); each login only ever sees its own fleet(s) — Filament tenancy scopes every
 * page and query to the fleet in the URL, and only its owner and managers may open it.
 */
class FleetPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('fleet')
            ->path('fleet')
            ->login(Login::class)
            ->registration(Register::class)
            ->passwordReset(ResetPassword::class)
            ->brandName('Ride Coop Fleets')
            ->favicon(asset('favicon.ico'))
            ->colors(['primary' => Color::Sky])
            ->defaultAvatarProvider(\App\Filament\Support\InitialsAvatarProvider::class)
            ->tenant(Fleet::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterFleet::class)
            ->tenantProfile(EditFleet::class)
            ->discoverResources(in: app_path('Filament/Fleet/Resources'), for: 'App\Filament\Fleet\Resources')
            ->discoverPages(in: app_path('Filament/Fleet/Pages'), for: 'App\Filament\Fleet\Pages')
            ->navigationItems([
                NavigationItem::make('Fleet settings')
                    ->icon(Heroicon::OutlinedCog6Tooth)->sort(9)
                    ->url(fn (): string => EditFleet::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.fleet.tenant.profile'))
                    ->visible(fn (): bool => ($t = Filament::getTenant()) instanceof Fleet && (bool) Filament::auth()->user()?->isFleetOwner($t)),
            ])
            ->databaseNotifications(false)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => implode('', [
                '<link rel="stylesheet" href="'.asset('vendor/leaflet/leaflet.css').'">',
                '<script src="'.asset('vendor/leaflet/leaflet.js').'"></script>',
            ]))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
