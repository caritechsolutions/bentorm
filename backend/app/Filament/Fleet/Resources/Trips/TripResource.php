<?php

namespace App\Filament\Fleet\Resources\Trips;

use App\Filament\Fleet\Resources\Trips\Pages\ListTrips;
use App\Filament\Support\Ui;
use App\Models\Fleet;
use App\Models\FleetDriver;
use App\Models\Trip;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Trips in the fleet's cars and what each earned, by car and by driver. Riders' details are not shown. */
class TripResource extends Resource
{
    protected static ?string $model = Trip::class;

    protected static ?string $tenantRelationshipName = 'trips';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Trips & earnings';

    protected static ?string $slug = 'trips';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        /** @var Fleet $fleet */
        $fleet = Filament::getTenant();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['driver', 'vehicle'])->whereIn('status', ['completed', 'cancelled', 'accepted', 'arrived', 'in_progress']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('requested_at')->label('When')->dateTime('j M, g:i a')->sortable(),
                TextColumn::make('vehicle.plate')->label('Car')->weight('bold')->placeholder('—'),
                TextColumn::make('driver.name')->label('Driver')->placeholder('—'),
                TextColumn::make('pickup_address')->label('From → to')->limit(40)
                    ->description(fn (Trip $r) => mb_strimwidth((string) $r->dropoff_address, 0, 40, '…')),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('payment_method')->label('Paid by')->badge()->color('gray')->formatStateUsing(fn ($state) => ucfirst($state->value)),
                TextColumn::make('final_fare')->label('Fare')->alignEnd()->numeric(2)->placeholder('—'),
                TextColumn::make('driver_earnings')->label('You earn')->alignEnd()->numeric(2)->placeholder('—')->weight('medium')
                    ->summarize(Sum::make()->label('Total')->numeric(2)),
                TextColumn::make('tip_amount')->label('Tip')->alignEnd()->numeric(2)->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->summarize(Sum::make()->label('Tips')->numeric(2)),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')->label('Car')->options(fn () => $fleet->vehicles()->orderBy('plate')->pluck('plate', 'id')),
                SelectFilter::make('driver_id')->label('Driver')->options(fn () => FleetDriver::query()->where('fleet_id', $fleet->id)->whereNotNull('driver_id')
                    ->with('driver')->get()->mapWithKeys(fn ($d) => [$d->driver_id => $d->displayName()])),
                SelectFilter::make('period')->label('Period')->default('month')
                    ->options(['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'all' => 'All time'])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'today' => $q->where('requested_at', '>=', now()->startOfDay()),
                        'week' => $q->where('requested_at', '>=', now()->startOfWeek()),
                        'month' => $q->where('requested_at', '>=', now()->startOfMonth()),
                        default => $q,
                    }),
                Filter::make('date')->schema([DatePicker::make('from')->native(false), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($w, $d) => $w->whereDate('requested_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($w, $d) => $w->whereDate('requested_at', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTrips::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
