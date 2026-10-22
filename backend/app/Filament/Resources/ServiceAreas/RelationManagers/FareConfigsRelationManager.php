<?php

namespace App\Filament\Resources\ServiceAreas\RelationManagers;

use App\Models\VehicleType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

class FareConfigsRelationManager extends RelationManager
{
    protected static string $relationship = 'fareConfigs';

    protected static ?string $title = 'Rate cards';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->isService();
    }

    public function form(Schema $schema): Schema
    {
        $money = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->minValue(0)->required()->prefix($this->getOwnerRecord()->currency);

        return $schema->columns(3)->components([
            Select::make('vehicle_type')->label('Vehicle type')->required()
                ->options(fn () => VehicleType::query()->orderBy('sort_order')->pluck('name', 'key'))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('service_area_id', $this->getOwnerRecord()->id)),
            $money('base_fare', 'Base fare'),
            $money('minimum_fare', 'Minimum fare'),
            $money('per_km', 'Per km'),
            $money('per_minute', 'Per minute'),
            $money('booking_fee', 'Booking fee')->default(0),
            $money('cancellation_fee', 'Cancellation fee')->default(0),
            TextInput::make('free_wait_minutes')->label('Free waiting')->numeric()->suffix('min')->default(3)->required(),
            $money('wait_per_minute', 'Waiting per minute')->default(0),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        $cur = fn () => $this->getOwnerRecord()->currency;

        return $table
            ->description('All amounts exclude tax. Tax is added on top and the total is rounded up.')
            ->columns([
                TextColumn::make('vehicleType.name')->label('Vehicle type')->weight('bold'),
                TextColumn::make('base_fare')->money(fn () => $cur()),
                TextColumn::make('per_km')->label('/ km')->money(fn () => $cur()),
                TextColumn::make('per_minute')->label('/ min')->money(fn () => $cur()),
                TextColumn::make('minimum_fare')->label('Minimum')->money(fn () => $cur()),
                TextColumn::make('booking_fee')->label('Booking')->money(fn () => $cur()),
                TextColumn::make('cancellation_fee')->label('Cancel fee')->money(fn () => $cur()),
                TextColumn::make('wait_per_minute')->label('Wait / min')->money(fn () => $cur())
                    ->description(fn ($record) => "after {$record->free_wait_minutes} min"),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Add rate card')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
