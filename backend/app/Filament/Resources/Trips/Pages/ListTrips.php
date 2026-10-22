<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTrips extends ListRecords
{
    protected static string $resource = TripResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'active' => Tab::make('In progress')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', array_map(fn ($s) => $s->value, TripStatus::active()))),
            'completed' => Tab::make('Completed')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'completed')),
            'cancelled' => Tab::make('Cancelled / no driver')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['cancelled', 'no_drivers'])),
        ];
    }
}
