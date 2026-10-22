<?php

namespace App\Filament\Resources\ServiceAreas;

use App\Enums\AreaType;
use App\Filament\Resources\ServiceAreas\Pages\CreateServiceArea;
use App\Filament\Resources\ServiceAreas\Pages\EditServiceArea;
use App\Filament\Resources\ServiceAreas\Pages\ListServiceAreas;
use App\Filament\Resources\ServiceAreas\RelationManagers\FareConfigsRelationManager;
use App\Filament\Resources\ServiceAreas\RelationManagers\FareRulesRelationManager;
use App\Filament\Support\Ui;
use App\Models\ServiceArea;
use App\Support\GeoJson;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ServiceAreaResource extends Resource
{
    protected static ?string $model = ServiceArea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAmericas;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & areas';

    protected static ?string $navigationLabel = 'Areas & zones';

    protected static ?string $modelLabel = 'area';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $isService = fn (Get $get) => ($get('type') instanceof AreaType ? $get('type')->value : $get('type')) === 'service';
        $percent = fn (string $name, string $label, string $help) => TextInput::make($name)->label($label)->numeric()->suffix('%')->helperText($help)
            ->formatStateUsing(fn ($state) => $state === null ? null : round($state * 100, 2))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : round($state / 100, 4));

        return $schema->components([
            Section::make('Area')->columns(3)->columnSpanFull()->schema([
                Select::make('type')->required()->live()
                    ->options(['service' => 'Service area (island / country)', 'zone' => 'Pricing zone (airport, port…)', 'blocked' => 'Blocked — no pickups or drop-offs'])
                    ->disabledOn('edit'),
                TextInput::make('name')->required()->maxLength(128),
                TextInput::make('code')->required()->maxLength(32)->unique(ignoreRecord: true)->helperText('Short ID, e.g. BB or BB-GAIA'),
                Select::make('parent_id')->label('Inside service area')
                    ->options(fn () => ServiceArea::query()->where('type', 'service')->pluck('name', 'id'))
                    ->visible(fn (Get $get) => ! $isService($get))
                    ->required(fn (Get $get) => $get('type') === 'zone'),
                TextInput::make('message')->label('Message shown to riders')->maxLength(255)->columnSpan(2)
                    ->visible(fn (Get $get) => $get('type') === 'blocked'),
                TextInput::make('priority')->numeric()->default(0)->helperText('Higher wins where areas overlap'),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false),
            ]),
            Section::make('Money & locale')->columns(4)->columnSpanFull()->visible($isService)->schema([
                TextInput::make('country_code')->label('Country')->length(2)->placeholder('BB'),
                TextInput::make('currency')->required($isService)->length(3)->placeholder('BBD'),
                Select::make('timezone')->required($isService)->searchable()
                    ->options(fn () => collect(\DateTimeZone::listIdentifiers(\DateTimeZone::AMERICA))->mapWithKeys(fn ($z) => [$z => $z]))
                    ->default('America/Barbados'),
                TextInput::make('tax_label')->placeholder('VAT'),
                $percent('tax_rate', 'Tax rate', 'Added on top of rate-card amounts'),
                $percent('commission_rate', 'Platform commission', 'Share of fare (ex tax) kept by the platform'),
                $percent('hold_buffer_rate', 'Card hold buffer', 'Hold = estimate + this %'),
                TextInput::make('rounding_increment')->label('Round fares up to')->numeric()->prefix('nearest')->placeholder('0.50'),
            ]),
            Section::make('Shape on the map')->columnSpanFull()->schema([
                ViewField::make('polygon')->hiddenLabel()->view('filament.forms.area-map')->required()
                    ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                        try {
                            GeoJson::normalise($value);
                        } catch (\InvalidArgumentException $e) {
                            $fail('Draw a valid shape: '.$e->getMessage());
                        }
                    }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('type')
            ->columns([
                TextColumn::make('type')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('name')->searchable()->weight('medium')->description(fn (ServiceArea $record) => $record->parent?->name),
                TextColumn::make('code')->fontFamily('mono'),
                TextColumn::make('currency')->placeholder('—'),
                TextColumn::make('tax_rate')->label('Tax')->formatStateUsing(fn ($state) => $state !== null ? round($state * 100, 2).'%' : '—'),
                TextColumn::make('commission_rate')->label('Commission')->formatStateUsing(fn ($state) => $state !== null ? round($state * 100, 2).'%' : '—'),
                TextColumn::make('fare_configs_count')->counts('fareConfigs')->label('Rate cards'),
                TextColumn::make('fare_rules_count')->counts('fareRules')->label('Rules'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([SelectFilter::make('type')->options(['service' => 'Service', 'zone' => 'Zone', 'blocked' => 'Blocked'])])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [FareConfigsRelationManager::class, FareRulesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceAreas::route('/'),
            'create' => CreateServiceArea::route('/create'),
            'edit' => EditServiceArea::route('/{record}/edit'),
        ];
    }
}
