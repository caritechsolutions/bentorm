<?php

namespace App\Filament\Resources\Drivers;

use App\Enums\DriverStatus;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\Pages\ViewDriver;
use App\Filament\Resources\Drivers\RelationManagers\VehiclesRelationManager;
use App\Filament\Support\Ui;
use App\Models\DriverProfile;
use App\Models\ServiceArea;
use App\Services\ComplianceService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DriverResource extends Resource
{
    protected static ?string $model = DriverProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Drivers';

    protected static ?string $navigationLabel = 'Drivers';

    protected static ?string $modelLabel = 'driver';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'user.name';

    public static function getNavigationBadge(): ?string
    {
        $n = DriverProfile::query()->where('status', DriverStatus::Pending->value)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Applications awaiting approval';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Driver details')->columns(2)->schema([
                Select::make('service_area_id')->label('Area')
                    ->options(fn () => ServiceArea::query()->where('type', 'service')->pluck('name', 'id')),
                TextInput::make('licence_number')->maxLength(64),
                DatePicker::make('licence_expiry')->native(false),
                TextInput::make('psv_permit_number')->label('PSV permit number')->maxLength(64),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Driver')->columnSpan(2)->columns(2)->schema([
                    TextEntry::make('user.name')->label('Name')->weight('bold'),
                    TextEntry::make('user.phone')->label('Phone')->copyable(),
                    TextEntry::make('user.email')->label('Email')->placeholder('—'),
                    TextEntry::make('serviceArea.name')->label('Area')->placeholder('Not set'),
                    TextEntry::make('licence_number')->placeholder('—'),
                    TextEntry::make('licence_expiry')->date()->placeholder('—'),
                    TextEntry::make('psv_permit_number')->label('PSV permit')->placeholder('—'),
                    TextEntry::make('user.created_at')->label('Signed up')->since(),
                ]),
                Section::make('Status')->columnSpan(1)->schema([
                    TextEntry::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                    TextEntry::make('status_note')->label('Note to driver')->placeholder('—'),
                    IconEntry::make('is_online')->label('Online now')->boolean(),
                    TextEntry::make('rating_avg')->label('Rating')->formatStateUsing(fn ($state, DriverProfile $record) => number_format((float) $state, 2).' ★ ('.$record->rating_count.')'),
                    TextEntry::make('completed_trips')->label('Completed trips'),
                    TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                ]),
            ]),
            Section::make('What is blocking this driver?')->columnSpanFull()
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->schema([
                    TextEntry::make('issues')->hiddenLabel()
                        ->state(fn (DriverProfile $record) => $record->user ? (app(ComplianceService::class)->canGoOnline($record->user) ?: ['Nothing — this driver can go online.']) : ['No user'])
                        ->listWithLineBreaks()->bulleted(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'serviceArea', 'currentVehicle']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Name')->searchable()->weight('medium'),
                TextColumn::make('user.phone')->label('Phone')->searchable(),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state))->sortable(),
                TextColumn::make('serviceArea.code')->label('Area')->placeholder('—'),
                TextColumn::make('currentVehicle.plate')->label('Vehicle')->placeholder('—')
                    ->description(fn (DriverProfile $record) => $record->currentVehicle ? "{$record->currentVehicle->make} {$record->currentVehicle->model} · {$record->currentVehicle->vehicle_type}" : null),
                IconColumn::make('is_online')->label('Online')->boolean(),
                TextColumn::make('pending_docs')->label('Docs to review')
                    ->state(fn (DriverProfile $record) => $record->documents()->where('status', 'pending')->count()
                        + \App\Models\Document::query()->where('documentable_type', \App\Models\Vehicle::class)
                            ->whereIn('documentable_id', $record->vehicles()->select('id'))->where('status', 'pending')->count())
                    ->badge()->color(fn ($state) => $state ? 'warning' : 'gray'),
                TextColumn::make('rating_avg')->label('Rating')->numeric(2)->sortable(),
                TextColumn::make('completed_trips')->label('Trips')->sortable(),
                TextColumn::make('created_at')->label('Applied')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(DriverStatus::cases())->mapWithKeys(fn ($c) => [$c->value => Ui::label($c)])),
                SelectFilter::make('service_area_id')->label('Area')->options(fn () => ServiceArea::query()->where('type', 'service')->pluck('name', 'id')),
                TernaryFilter::make('is_online')->label('Online'),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (DriverProfile $record) => static::getUrl('view', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class, VehiclesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'view' => ViewDriver::route('/{record}'),
            'edit' => EditDriver::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // drivers sign up in the app
    }
}
