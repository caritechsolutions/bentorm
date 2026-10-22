<?php

namespace App\Filament\Resources\VehicleTypes;

use App\Filament\Resources\VehicleTypes\Pages\ManageVehicleTypes;
use App\Models\VehicleType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class VehicleTypeResource extends Resource
{
    protected static ?string $model = VehicleType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & areas';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('key')->required()->maxLength(32)->alphaDash()->unique(ignoreRecord: true)->disabledOn('edit')
                ->helperText('Used by the apps, e.g. standard, xl'),
            TextInput::make('name')->required()->maxLength(64),
            TextInput::make('description')->maxLength(255)->columnSpanFull(),
            TextInput::make('max_passengers')->numeric()->required()->minValue(1)->maxValue(16)->default(4),
            TextInput::make('max_luggage')->label('Max luggage (bags)')->numeric()->required()->minValue(0)->maxValue(20)->default(2),
            TextInput::make('icon_url')->url()->maxLength(500),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (VehicleType $record) => $record->description),
                TextColumn::make('key')->fontFamily('mono'),
                TextColumn::make('max_passengers')->label('Passengers'),
                TextColumn::make('max_luggage')->label('Bags'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVehicleTypes::route('/')];
    }
}
