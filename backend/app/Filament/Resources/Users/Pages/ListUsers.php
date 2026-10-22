<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'riders' => Tab::make('Riders')->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'rider')),
            'drivers' => Tab::make('Drivers')->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'driver')),
            'admins' => Tab::make('Admins')->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'admin')),
        ];
    }
}
