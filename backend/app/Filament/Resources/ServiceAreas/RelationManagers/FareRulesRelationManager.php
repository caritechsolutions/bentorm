<?php

namespace App\Filament\Resources\ServiceAreas\RelationManagers;

use App\Enums\FareRuleKind;
use App\Models\FareRule;
use App\Models\ServiceArea;
use App\Models\VehicleType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FareRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'fareRules';

    protected static ?string $title = 'Fare rules';

    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->isService();
    }

    public function form(Schema $schema): Schema
    {
        $kind = fn (Get $get) => $get('kind') instanceof FareRuleKind ? $get('kind')->value : $get('kind');
        $is = fn (string ...$k) => fn (Get $get) => in_array($kind($get), $k, true);
        $zones = fn () => ServiceArea::query()->where('type', 'zone')->where('parent_id', $this->getOwnerRecord()->id)->pluck('name', 'id');
        $cur = $this->getOwnerRecord()->currency;

        return $schema->columns(2)->components([
            Select::make('kind')->label('Rule type')->required()->live()->columnSpanFull()->options([
                'time_multiplier' => 'Time of day — multiply the fare in a time window (e.g. late night 1.25×)',
                'surge' => 'Surge — multiply the fare for a set period (e.g. carnival, bad weather)',
                'zone_fee' => 'Zone fee — flat fee when pickup / drop-off is in a zone (e.g. airport)',
                'fixed_route' => 'Fixed fare — flat price between two zones (replaces the meter)',
            ]),
            TextInput::make('name')->required()->maxLength(128),
            Select::make('vehicle_type')->label('Applies to')->placeholder('All vehicle types')
                ->options(fn () => VehicleType::query()->orderBy('sort_order')->pluck('name', 'key')),
            TextInput::make('multiplier')->numeric()->minValue(0.1)->maxValue(10)->step(0.05)->suffix('×')
                ->visible($is('time_multiplier', 'surge'))->required($is('time_multiplier', 'surge')),
            TextInput::make('amount')->numeric()->minValue(0)->prefix($cur)->helperText('Excludes tax')
                ->visible($is('zone_fee', 'fixed_route'))->required($is('zone_fee', 'fixed_route')),
            Select::make('pickup_zone_id')->label(fn (Get $get) => $kind($get) === 'zone_fee' ? 'Zone' : 'From zone')->options($zones)
                ->visible($is('zone_fee', 'fixed_route'))->required($is('zone_fee', 'fixed_route')),
            Radio::make('zone_applies_to')->label('Charge when the zone is the')->inline()->default('pickup')
                ->options(['pickup' => 'Pickup', 'dropoff' => 'Drop-off', 'either' => 'Either'])->visible($is('zone_fee')),
            Select::make('dropoff_zone_id')->label('To zone')->options($zones)->visible($is('fixed_route'))->required($is('fixed_route')),
            Toggle::make('bidirectional')->label('Also applies in the other direction')->default(true)->visible($is('fixed_route')),
            CheckboxList::make('days')->label('Days')->options(self::DAYS)->columns(7)->columnSpanFull()
                ->helperText('Leave all unticked for every day')->visible($is('time_multiplier', 'zone_fee', 'fixed_route')),
            TimePicker::make('start_time')->seconds(false)->visible($is('time_multiplier', 'zone_fee', 'fixed_route'))
                ->required($is('time_multiplier'))->helperText('Local time. Windows may cross midnight (23:00 → 05:00).'),
            TimePicker::make('end_time')->seconds(false)->visible($is('time_multiplier', 'zone_fee', 'fixed_route'))
                ->required(fn (Get $get) => filled($get('start_time'))),
            DateTimePicker::make('starts_at')->label('Starts')->seconds(false)->native(false)->visible($is('surge'))->default(now()),
            DateTimePicker::make('ends_at')->label('Ends')->seconds(false)->native(false)->visible($is('surge'))->after('starts_at'),
            TextInput::make('priority')->numeric()->default(0),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->label('Type')->badge()->color('gray')->formatStateUsing(fn ($state) => match ($state) {
                    FareRuleKind::TimeMultiplier => 'Time of day', FareRuleKind::Surge => 'Surge',
                    FareRuleKind::ZoneFee => 'Zone fee', FareRuleKind::FixedRoute => 'Fixed fare', default => (string) $state?->value,
                }),
                TextColumn::make('name')->weight('medium'),
                TextColumn::make('effect')->state(fn (FareRule $record) => $record->multiplier ? "×{$record->multiplier}" : ($this->getOwnerRecord()->currency.' '.number_format((float) $record->amount, 2))),
                TextColumn::make('when')->state(fn (FareRule $record) => self::when($record))->wrap(),
                TextColumn::make('where')->state(fn (FareRule $record) => self::where($record))->wrap()->placeholder('Anywhere'),
                TextColumn::make('vehicle_type')->label('Vehicle')->placeholder('All'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Add rule')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    private static function when(FareRule $r): string
    {
        $days = $r->days ? implode(' ', array_map(fn ($d) => self::DAYS[(int) $d] ?? $d, $r->days)) : 'Every day';
        $time = $r->start_time ? substr($r->start_time, 0, 5).'–'.substr((string) $r->end_time, 0, 5) : 'all day';
        if ($r->kind === FareRuleKind::Surge) {
            return ($r->starts_at?->format('d M H:i') ?? 'now').' → '.($r->ends_at?->format('d M H:i') ?? 'until switched off');
        }

        return "{$days}, {$time}";
    }

    private static function where(FareRule $r): ?string
    {
        return match ($r->kind) {
            FareRuleKind::ZoneFee => ($r->pickupZone?->name ?? '?').' ('.($r->zone_applies_to ?? 'either').')',
            FareRuleKind::FixedRoute => ($r->pickupZone?->name ?? '?').($r->bidirectional ? ' ⇄ ' : ' → ').($r->dropoffZone?->name ?? '?'),
            default => null,
        };
    }
}
