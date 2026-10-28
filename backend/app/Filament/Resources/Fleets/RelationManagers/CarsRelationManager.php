<?php

namespace App\Filament\Resources\Fleets\RelationManagers;

use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Support\Ui;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CarsRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    protected static ?string $title = 'Cars';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plate')->weight('bold'),
                TextColumn::make('make')->label('Car')->formatStateUsing(fn ($s, Vehicle $r) => "{$r->make} {$r->model}"),
                TextColumn::make('vehicle_type')->label('Type')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('id')->label('Driving now')->formatStateUsing(fn ($s, Vehicle $r) => $r->inUseBy()?->name ?? '—'),
            ])
            ->recordActions([Action::make('open')->label('Review')->url(fn (Vehicle $r) => VehicleResource::getUrl('view', ['record' => $r]))]);
    }
}
