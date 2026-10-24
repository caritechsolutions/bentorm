<?php

namespace App\Filament\Resources\AutoSurgeSettings;

use App\Filament\Resources\AutoSurgeSettings\Pages\ManageAutoSurgeSettings;
use App\Models\AutoSurgeSetting;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Per-area limits for automatic surge, and what it is doing right now. Switch it on in App features. */
class AutoSurgeSettingResource extends Resource
{
    protected static ?string $model = AutoSurgeSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Automatic surge';

    protected static ?string $modelLabel = 'automatic surge';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false; // one row per area, made automatically
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Toggle::make('enabled')->label('Automatic surge in this area')->columnSpanFull(),
            TextInput::make('window_minutes')->label('Count ride requests over the last')->numeric()->minValue(2)->maxValue(60)->suffix('min')->required(),
            TextInput::make('min_requests')->label('No surge with fewer requests than')->numeric()->minValue(1)->required(),
            TextInput::make('start_ratio')->label('Start when requests per free driver reach')->numeric()->minValue(0.5)->step(0.1)->required(),
            TextInput::make('per_ratio')->label('Add to the multiplier per extra request per driver')->numeric()->minValue(0.05)->step(0.05)->required(),
            TextInput::make('max_multiplier')->label('Never more than')->numeric()->minValue(1)->maxValue(5)->step(0.1)->prefix('×')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('30s')
            ->description(fn () => Features::on('auto_surge') ? 'On. Worked out every minute; a booking keeps the price it was quoted.' : 'Off — switch it on in Settings → App features.')
            ->columns([
                TextColumn::make('serviceArea.name')->label('Area')->weight('medium'),
                ToggleColumn::make('enabled')->label('On here'),
                TextColumn::make('current_multiplier')->label('Now')->formatStateUsing(fn ($state) => '×'.number_format((float) $state, 1))
                    ->color(fn ($state) => (float) $state > 1 ? 'warning' : null)->weight('bold'),
                TextColumn::make('demand')->label('Requests')->description(fn (AutoSurgeSetting $r) => 'last '.$r->window_minutes.' min'),
                TextColumn::make('supply')->label('Free drivers'),
                TextColumn::make('rule')->label('Rule')->state(fn (AutoSurgeSetting $r) => sprintf('from %.1f per driver, +%.2f each, max ×%.1f', $r->start_ratio, $r->per_ratio, $r->max_multiplier)),
                TextColumn::make('computed_at')->label('Updated')->since()->placeholder('—'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAutoSurgeSettings::route('/')];
    }
}
