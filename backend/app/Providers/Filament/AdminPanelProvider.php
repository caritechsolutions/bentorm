<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('Ride Coop Admin')
            ->colors(['primary' => Color::Teal])
            ->defaultAvatarProvider(\App\Filament\Support\InitialsAvatarProvider::class)
            ->navigationGroups([
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Drivers'),
                NavigationGroup::make('Riders & payments'),
                NavigationGroup::make('Shop'),
                NavigationGroup::make('Pricing & areas'),
                NavigationGroup::make('Settings'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->databaseNotifications(false)
            ->sidebarCollapsibleOnDesktop()
            // Maps (area editor, live map): Leaflet + Geoman are bundled in public/vendor, no CDN needed.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => implode('', [
                '<link rel="stylesheet" href="'.asset('vendor/leaflet/leaflet.css').'">',
                '<link rel="stylesheet" href="'.asset('vendor/leaflet-geoman/leaflet-geoman.css').'">',
                '<script src="'.asset('vendor/leaflet/leaflet.js').'"></script>',
                '<script src="'.asset('vendor/leaflet-geoman/leaflet-geoman.min.js').'"></script>',
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
