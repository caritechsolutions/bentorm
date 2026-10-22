<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Support\Ui;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plate')->weight('bold'),
                TextColumn::make('make')->formatStateUsing(fn ($state, Vehicle $record) => "{$record->make} {$record->model}".($record->year ? " ({$record->year})" : '')),
                TextColumn::make('vehicle_type')->label('Type')->badge()->color('gray'),
                TextColumn::make('seats')->formatStateUsing(fn ($state, Vehicle $record) => "{$record->seats} seats · {$record->luggage_capacity} bags"),
                TextColumn::make('features')->badge()->formatStateUsing(fn ($state) => Ui::features()[$state] ?? $state)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('current')->label('Current')->state(fn (Vehicle $record) => $this->getOwnerRecord()->current_vehicle_id === $record->id ? 'Yes' : '')->placeholder(''),
            ])
            ->recordActions([
                Action::make('open')->label('Review')->icon('heroicon-o-arrow-right')
                    ->url(fn (Vehicle $record) => VehicleResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
