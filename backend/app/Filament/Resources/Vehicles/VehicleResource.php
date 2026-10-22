<?php

namespace App\Filament\Resources\Vehicles;

use App\Enums\VehicleStatus;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Filament\Support\Ui;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\ComplianceService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Drivers';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'plate';

    public static function getNavigationBadge(): ?string
    {
        $n = Vehicle::query()->where('status', VehicleStatus::Pending->value)->count();

        return $n ? (string) $n : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vehicle')->columns(3)->schema([
                TextInput::make('plate')->required()->maxLength(16)
                    ->dehydrateStateUsing(fn ($state) => strtoupper(preg_replace('/[\s-]+/', '', (string) $state))),
                TextInput::make('make')->required(),
                TextInput::make('model')->required(),
                TextInput::make('year')->numeric()->minValue(1990)->maxValue(now()->year + 1),
                TextInput::make('colour'),
                Select::make('vehicle_type')->label('Type')->required()->options(fn () => VehicleType::query()->orderBy('sort_order')->pluck('name', 'key')),
                TextInput::make('seats')->label('Passenger seats')->numeric()->required()->minValue(1)->maxValue(16),
                TextInput::make('luggage_capacity')->label('Luggage (bags)')->numeric()->required()->minValue(0)->maxValue(20),
                CheckboxList::make('features')->options(Ui::features())->columns(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vehicle')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('plate')->weight('bold')->size('lg'),
                TextEntry::make('make')->label('Make / model')->formatStateUsing(fn ($s, Vehicle $record) => "{$record->make} {$record->model}".($record->year ? " ({$record->year})" : '')),
                TextEntry::make('colour')->placeholder('—'),
                TextEntry::make('vehicle_type')->label('Type')->badge()->color('gray'),
                TextEntry::make('seats')->label('Passenger seats'),
                TextEntry::make('luggage_capacity')->label('Luggage (bags)'),
                TextEntry::make('features')->badge()->formatStateUsing(fn ($state) => Ui::features()[$state] ?? $state)->placeholder('None'),
                TextEntry::make('driver.name')->label('Driver')
                    ->url(fn (Vehicle $record) => $record->driver?->driverProfile ? \App\Filament\Resources\Drivers\DriverResource::getUrl('view', ['record' => $record->driver->driverProfile]) : null),
                TextEntry::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextEntry::make('status_note')->label('Note')->placeholder('—'),
                TextEntry::make('issues')->label('Missing / pending documents')->columnSpan(2)
                    ->state(fn (Vehicle $record) => app(ComplianceService::class)->vehicleIssues($record) ?: ['All required documents approved'])
                    ->listWithLineBreaks()->bulleted(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('driver'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('plate')->searchable()->weight('bold'),
                TextColumn::make('make')->label('Vehicle')->formatStateUsing(fn ($s, Vehicle $record) => "{$record->make} {$record->model}")->searchable(['make', 'model']),
                TextColumn::make('vehicle_type')->label('Type')->badge()->color('gray'),
                TextColumn::make('seats')->label('Capacity')->formatStateUsing(fn ($s, Vehicle $record) => "{$record->seats} pax · {$record->luggage_capacity} bags"),
                TextColumn::make('features')->badge()->formatStateUsing(fn ($state) => Ui::features()[$state] ?? $state)->placeholder('—'),
                TextColumn::make('driver.name')->label('Driver')->searchable(),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('created_at')->label('Added')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(VehicleStatus::cases())->mapWithKeys(fn ($c) => [$c->value => Ui::label($c)])),
                SelectFilter::make('vehicle_type')->label('Type')->options(fn () => VehicleType::query()->pluck('name', 'key')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicles::route('/'),
            'view' => ViewVehicle::route('/{record}'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // drivers add vehicles in the app
    }
}
