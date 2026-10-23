<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Models\TripOffer;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Which drivers were offered this ride, in which wave, and what they did — to see dispatch at work. */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Driver offers';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'asc')
            ->columns([
                TextColumn::make('wave')->placeholder('—'),
                TextColumn::make('driver.name')->label('Driver'),
                TextColumn::make('distance_m')->label('Distance')->formatStateUsing(fn ($state) => number_format($state / 1000, 1).' km'),
                TextColumn::make('eta_s')->label('Arrival by road')->placeholder('—')->formatStateUsing(fn ($state) => (int) ceil($state / 60).' min'),
                TextColumn::make('radius_m')->label('Search radius')->placeholder('—')->formatStateUsing(fn ($state) => number_format($state / 1000, 1).' km'),
                TextColumn::make('response')->badge()->color(fn (TripOffer $r) => match ($r->response?->value) {
                    'accepted' => 'success', 'declined' => 'danger', 'pending' => 'warning', default => 'gray',
                }),
                TextColumn::make('offered_at')->dateTime('H:i:s'),
                TextColumn::make('responded_at')->dateTime('H:i:s')->placeholder('—'),
            ]);
    }
}
