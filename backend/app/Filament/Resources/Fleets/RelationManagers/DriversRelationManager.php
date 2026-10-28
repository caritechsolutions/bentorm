<?php

namespace App\Filament\Resources\Fleets\RelationManagers;

use App\Models\FleetDriver;
use App\Services\FleetService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DriversRelationManager extends RelationManager
{
    protected static string $relationship = 'drivers';

    protected static ?string $title = 'Drivers';

    public function table(Table $table): Table
    {
        $held = $this->getOwnerRecord()->cashHeld();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['driver', 'vehicles']))
            ->columns([
                TextColumn::make('name')->label('Driver')->state(fn (FleetDriver $r) => $r->displayName())->description(fn (FleetDriver $r) => $r->phone),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'active' => 'success', 'invited' => 'warning', default => 'gray' }),
                TextColumn::make('cars')->label('May drive')->state(fn (FleetDriver $r) => $r->vehicles->pluck('plate')->join(', ') ?: '—'),
                TextColumn::make('cash')->label('Cash held')->state(fn (FleetDriver $r) => number_format($held[$r->driver_id] ?? 0, 2)),
            ])
            ->recordActions([
                Action::make('remove')->label('Remove')->color('danger')->requiresConfirmation()
                    ->visible(fn (FleetDriver $r) => in_array($r->status, ['active', 'invited'], true))
                    ->action(fn (FleetDriver $r) => app(FleetService::class)->remove($r)),
            ]);
    }
}
