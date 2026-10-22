<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Models\Document;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Services\DriverLocationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '15s';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = now('America/Barbados')->startOfDay()->utc();
        $completed = Trip::query()->where('status', TripStatus::Completed->value)->where('completed_at', '>=', $today);
        try {
            $online = count(app(DriverLocationService::class)->allLive());
        } catch (\Throwable) {
            $online = DriverProfile::query()->where('is_online', true)->count();
        }

        return [
            Stat::make('Drivers online', $online)->icon('heroicon-o-signal')
                ->url(\App\Filament\Pages\LiveMap::getUrl()),
            Stat::make('Trips in progress', Trip::query()->whereIn('status', array_map(fn ($s) => $s->value, TripStatus::active()))->count())->icon('heroicon-o-map'),
            Stat::make('Completed today', (clone $completed)->count())
                ->description('BBD '.number_format((float) (clone $completed)->sum('final_fare'), 2).' fares · BBD '.number_format((float) (clone $completed)->sum('platform_fee'), 2).' commission')
                ->icon('heroicon-o-check-circle'),
            Stat::make('No driver found today', Trip::query()->where('status', TripStatus::NoDrivers->value)->where('created_at', '>=', $today)->count())
                ->color('danger')->icon('heroicon-o-exclamation-triangle'),
            Stat::make('Applications waiting', DriverProfile::query()->where('status', 'pending')->count())
                ->description(Document::query()->where('status', 'pending')->count().' documents to review')
                ->color('warning')->icon('heroicon-o-identification')
                ->url(\App\Filament\Resources\Documents\DocumentResource::getUrl()),
            Stat::make('Money owed by riders', 'BBD '.number_format((float) Trip::query()->where('payment_status', 'pending')->where('amount_due', '>', 0)->sum('amount_due'), 2))
                ->icon('heroicon-o-banknotes'),
        ];
    }
}
