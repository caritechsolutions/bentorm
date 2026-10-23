<?php

namespace App\Filament\Resources\DispatchSettings;

use App\Filament\Resources\DispatchSettings\Pages\CreateDispatchSetting;
use App\Filament\Resources\DispatchSettings\Pages\EditDispatchSetting;
use App\Filament\Resources\DispatchSettings\Pages\ListDispatchSettings;
use App\Models\DispatchSetting;
use App\Models\ServiceArea;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

/** How ride requests reach drivers: a default, plus optional overrides per service area. */
class DispatchSettingResource extends Resource
{
    protected static ?string $model = DispatchSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Driver dispatch';

    protected static ?string $modelLabel = 'dispatch setting';

    protected static ?string $pluralModelLabel = 'driver dispatch';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        $km = fn (string $name, string $label) => TextInput::make($name)->label($label)->required()->numeric()->minValue(0)->maxValue(100)->step(0.5)->suffix('km')
            ->formatStateUsing(fn ($state) => $state === null ? null : round($state / 1000, 1))
            ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 1000));

        return $schema->components([
            Section::make('Where this applies')->columnSpanFull()->schema([
                Select::make('service_area_id')->label('Service area')
                    ->placeholder('Default (every area without its own setting)')
                    ->options(fn () => ServiceArea::query()->where('type', 'service')->orderBy('name')->pluck('name', 'id'))
                    ->disabled(fn (?DispatchSetting $record) => $record?->isDefault() ?? false)
                    ->required(fn (?DispatchSetting $record) => ! ($record?->isDefault() ?? false))
                    ->rules(fn (?DispatchSetting $record) => [Rule::unique('dispatch_settings', 'service_area_id')->ignore($record?->id)])
                    ->validationMessages(['unique' => 'This area already has its own setting — edit that one instead.'])
                    ->helperText('An area\'s own setting replaces the default for rides picked up in that area.'),
            ]),
            Section::make('Who gets the request')->columns(2)->columnSpanFull()->schema([
                Radio::make('strategy')->required()->live()->default('waves')->options(DispatchSetting::STRATEGIES)
                    ->descriptions([
                        'waves' => 'The best few drivers see it first. If none accept in time, the next best do.',
                        'broadcast' => 'Every driver in range sees it at once; the first to accept gets it. Fastest pickup, but most drivers miss out.',
                    ]),
                Radio::make('ranking')->label('Who counts as "best"')->required()->live()->default('distance')->options(DispatchSetting::RANKINGS)
                    ->descriptions([
                        'distance' => 'Free, instant. Can pick a driver who is close but a long way round by road.',
                        'eta' => 'Asks Google (or OSRM) for each driver\'s driving time. Most accurate; each check is a paid Route Matrix lookup.',
                        'longest_idle' => 'Fairest to drivers: whoever has waited longest since their last ride, within the search radius.',
                    ]),
                TextInput::make('batch_size')->label('Drivers per wave')->numeric()->required()->minValue(1)->maxValue(20)->default(3)
                    ->visible(fn (Get $get) => $get('strategy') !== 'broadcast')
                    ->helperText('1 = one driver at a time.'),
                TextInput::make('broadcast_limit')->label('Most drivers offered at once')->numeric()->required()->minValue(1)->maxValue(100)->default(25)
                    ->visible(fn (Get $get) => $get('strategy') === 'broadcast'),
                TextInput::make('eta_candidates')->label('Drivers checked by road')->numeric()->required()->minValue(1)->maxValue(25)->default(10)
                    ->visible(fn (Get $get) => $get('ranking') === 'eta')
                    ->helperText('The nearest this many (in a straight line) are checked each wave. Each one is a billed lookup.'),
            ]),
            Section::make('Search area and timing')->columns(3)->columnSpanFull()->schema([
                $km('radius_start_m', 'Start searching within')->default(3)->minValue(0.5),
                $km('radius_step_m', 'Widen each time by')->default(3)->helperText('0 = never widen.'),
                $km('radius_max_m', 'Up to')->default(12)->minValue(0.5)
                    ->rule(fn (Get $get) => fn (string $attr, $value, \Closure $fail) => (float) $value < (float) $get('radius_start_m') ? $fail('Must be at least the starting radius.') : null),
                TextInput::make('offer_ttl_seconds')->label('Seconds to accept')->numeric()->required()->minValue(8)->maxValue(120)->default(20)->suffix('s')->live(onBlur: true),
                TextInput::make('max_waves')->label('Waves before "no drivers"')->numeric()->required()->minValue(1)->maxValue(20)->default(4)->live(onBlur: true)
                    ->helperText(fn (Get $get) => 'A rider waits at most about '.((int) $get('max_waves') * (int) $get('offer_ttl_seconds')).' seconds before "no drivers available".'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('serviceArea')->orderByRaw('service_area_id is not null')->orderBy('id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('serviceArea.name')->label('Applies to')->placeholder('Default')->weight('medium'),
                TextColumn::make('strategy')->formatStateUsing(fn ($state) => DispatchSetting::STRATEGIES[$state] ?? $state)
                    ->description(fn (DispatchSetting $r) => $r->isBroadcast() ? "up to {$r->broadcast_limit} drivers" : "{$r->batch_size} per wave"),
                TextColumn::make('ranking')->formatStateUsing(fn ($state) => DispatchSetting::RANKINGS[$state] ?? $state),
                TextColumn::make('radius_start_m')->label('Search radius')->formatStateUsing(fn ($state, DispatchSetting $r) => $r->radiusSummary()),
                TextColumn::make('offer_ttl_seconds')->label('Timing')
                    ->formatStateUsing(fn ($state, DispatchSetting $r) => "{$r->offer_ttl_seconds}s × {$r->max_waves} waves")
                    ->description(fn (DispatchSetting $r) => 'max wait ~'.$r->longestWaitSeconds().'s'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDispatchSettings::route('/'),
            'create' => CreateDispatchSetting::route('/create'),
            'edit' => EditDispatchSetting::route('/{record}/edit'),
        ];
    }
}
