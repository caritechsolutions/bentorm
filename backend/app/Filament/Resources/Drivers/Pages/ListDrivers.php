<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Awaiting approval')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'approved' => Tab::make('Approved')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'online' => Tab::make('Online now')->modifyQueryUsing(fn (Builder $query) => $query->where('is_online', true)),
            'suspended' => Tab::make('Suspended / rejected')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['suspended', 'rejected'])),
        ];
    }
}
