<?php

namespace App\Providers\Filament;

use App\Filament\Store\Pages\Auth\Login;
use App\Filament\Store\Pages\Auth\Register;
use App\Filament\Store\Pages\Auth\ResetPassword;
use App\Filament\Store\Pages\EditStore;
use App\Filament\Store\Pages\RegisterStore;
use App\Models\Store;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The store portal (/store): one address for every shop. Each login only ever sees its own store(s) —
 * Filament tenancy scopes every page and query to the store in the URL, and only members may open it.
 */
class StorePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('store')
            ->path('store')
            ->login(Login::class)
            ->registration(Register::class)
            ->passwordReset(ResetPassword::class)
            ->brandName('Ride Coop Stores')
            ->favicon(asset('favicon.ico'))
            ->colors(['primary' => Color::Amber])
            ->defaultAvatarProvider(\App\Filament\Support\InitialsAvatarProvider::class)
            ->tenant(Store::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterStore::class)
            ->tenantProfile(EditStore::class)
            ->discoverResources(in: app_path('Filament/Store/Resources'), for: 'App\Filament\Store\Resources')
            ->discoverPages(in: app_path('Filament/Store/Pages'), for: 'App\Filament\Store\Pages')
            ->navigationItems([
                \Filament\Navigation\NavigationItem::make('Store settings')
                    ->icon(\Filament\Support\Icons\Heroicon::OutlinedCog6Tooth)->sort(4)
                    ->url(fn (): string => EditStore::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.store.tenant.profile'))
                    ->visible(fn (): bool => ($t = \Filament\Facades\Filament::getTenant()) instanceof Store && (bool) \Filament\Facades\Filament::auth()->user()?->isStoreOwner($t)),
            ])
            ->databaseNotifications(false)
            // New-order sound + banner on every page of a store.
            ->renderHook(PanelsRenderHook::BODY_END, function (): string {
                $store = \Filament\Facades\Filament::getTenant();

                return $store instanceof Store ? view('filament.store.order-alerts', [
                    'url' => route('store.order-alerts', $store),
                    'ordersUrl' => \App\Filament\Store\Resources\Orders\OrderResource::getUrl('index', ['tab' => 'new']),
                ])->render() : '';
            })
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
