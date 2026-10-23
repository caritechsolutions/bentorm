<?php

namespace App\Filament\Resources\Trips;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\Pages\ViewTrip;
use App\Filament\Resources\Trips\RelationManagers\OffersRelationManager;
use App\Filament\Resources\Trips\RelationManagers\PaymentsRelationManager;
use App\Filament\Support\Ui;
use App\Models\ServiceArea;
use App\Models\Trip;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TripResource extends Resource
{
    protected static ?string $model = Trip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'public_id';

    public static function getRecordRouteKeyName(): ?string
    {
        return 'public_id';
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Trip::query()->whereIn('status', array_map(fn ($s) => $s->value, TripStatus::active()))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Trips in progress';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Trip')->columnSpan(2)->columns(2)->schema([
                    TextEntry::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                    TextEntry::make('vehicle_type')->label('Vehicle type')->badge()->color('gray'),
                    TextEntry::make('rider.name')->label('Rider')->formatStateUsing(fn ($state, Trip $record) => "{$state} · {$record->rider?->phone}"),
                    TextEntry::make('driver.name')->label('Driver')->placeholder('Not assigned')
                        ->formatStateUsing(fn ($state, Trip $record) => "{$state} · {$record->driver?->phone}"),
                    TextEntry::make('vehicle.plate')->label('Vehicle')->placeholder('—')
                        ->formatStateUsing(fn ($state, Trip $record) => "{$state} · {$record->vehicle?->make} {$record->vehicle?->model}"),
                    TextEntry::make('passengers')->formatStateUsing(fn ($state, Trip $record) => "{$state} passengers · {$record->luggage} bags"),
                    TextEntry::make('pickup_address')->label('Pickup')->placeholder(fn (Trip $record) => "{$record->pickup_lat}, {$record->pickup_lng}"),
                    TextEntry::make('stops_list')->label('Stops')->placeholder('None')
                        ->state(fn (Trip $record) => collect($record->stops ?? [])->values()->map(fn ($s, $i) => ($i + 1).'. '.($s['address'] ?? "{$s['lat']}, {$s['lng']}")
                            .(! empty($s['reached_at']) ? ' ✓' : ''))->all())
                        ->listWithLineBreaks(),
                    TextEntry::make('dropoff_address')->label('Drop-off')->placeholder(fn (Trip $record) => "{$record->dropoff_lat}, {$record->dropoff_lng}"),
                    TextEntry::make('stop_change.status')->label('Stop change')->placeholder('—')
                        ->formatStateUsing(fn ($state) => ['pending' => 'Waiting for the driver', 'accepted' => 'Accepted by the driver', 'declined' => 'Declined by the driver'][$state] ?? $state),
                    TextEntry::make('pin_verified_at')->label('Ride PIN')
                        ->state(fn (Trip $record) => $record->pin_verified_at ? 'Checked at '.$record->pin_verified_at->setTimezone($record->serviceArea?->timezone ?: config('app.timezone'))->format('g:i a') : ($record->pin ? 'Not entered yet' : 'No PIN on this ride'))
                        ->helperText(fn (Trip $record) => $record->pin_attempts > 0 ? "{$record->pin_attempts} wrong attempt(s)" : null),
                    TextEntry::make('required_features')->label('Requested features')->badge()->formatStateUsing(fn ($state) => Ui::features()[$state] ?? $state)->placeholder('None'),
                    TextEntry::make('rider_note')->placeholder('—'),
                    TextEntry::make('cancellation_reason')->label('Cancelled')
                        ->visible(fn (Trip $record) => $record->cancelled_at !== null)
                        ->formatStateUsing(fn ($state, Trip $record) => "By {$record->cancelled_by}: {$state}"),
                ]),
                Section::make('Money')->columnSpan(1)->schema([
                    TextEntry::make('final_fare')->label('Fare')->weight('bold')->size('lg')
                        ->state(fn (Trip $record) => Ui::money($record->final_fare ?? $record->estimated_fare, $record->currency).($record->final_fare === null ? ' (estimate)' : '')),
                    TextEntry::make('payment_method')->label('Payment')->formatStateUsing(fn ($state, Trip $record) => ucfirst($state->value).($record->card ? " · {$record->card->brand} ••{$record->card->last4}" : '')),
                    TextEntry::make('payment_status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                    TextEntry::make('amount_due')->label('Still owed')->visible(fn (Trip $record) => $record->amount_due > 0)
                        ->state(fn (Trip $record) => Ui::money($record->amount_due, $record->currency))->color('danger'),
                    TextEntry::make('hold_amount')->label('Card hold')->placeholder('—')->state(fn (Trip $record) => $record->hold_amount ? Ui::money($record->hold_amount, $record->currency) : null),
                    TextEntry::make('driver_earnings')->label('Driver earns')->state(fn (Trip $record) => Ui::money($record->driver_earnings, $record->currency)),
                    TextEntry::make('platform_fee')->label('Platform fee')->state(fn (Trip $record) => Ui::money($record->platform_fee, $record->currency)),
                    TextEntry::make('tax_amount')->label('Tax')->state(fn (Trip $record) => Ui::money($record->tax_amount, $record->currency)),
                ]),
            ]),
            Grid::make(2)->columnSpanFull()->schema([
                Section::make('Fare breakdown')->schema([
                    RepeatableEntry::make('fare_breakdown.lines')->hiddenLabel()->table([
                        \Filament\Infolists\Components\RepeatableEntry\TableColumn::make('Item'),
                        \Filament\Infolists\Components\RepeatableEntry\TableColumn::make('Amount'),
                    ])->schema([
                        TextEntry::make('label'),
                        TextEntry::make('amount')->numeric(2),
                    ]),
                ]),
                Section::make('Timeline')->columns(2)->schema([
                    TextEntry::make('requested_at')->dateTime()->placeholder('—'),
                    TextEntry::make('accepted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('arrived_at')->dateTime()->placeholder('—'),
                    TextEntry::make('started_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                    TextEntry::make('estimated_distance_m')->label('Distance est. / actual')
                        ->state(fn (Trip $record) => round(($record->estimated_distance_m ?? 0) / 1000, 1).' km / '.($record->actual_distance_m !== null ? round($record->actual_distance_m / 1000, 1).' km' : '—')),
                    TextEntry::make('actual_duration_s')->label('Duration')->state(fn (Trip $record) => $record->actual_duration_s !== null ? round($record->actual_duration_s / 60).' min' : '—'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['rider', 'driver', 'vehicle']))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('public_id')->label('Trip')->formatStateUsing(fn ($state) => substr($state, -8))->searchable()->fontFamily('mono'),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('rider.name')->label('Rider')->searchable(),
                TextColumn::make('driver.name')->label('Driver')->placeholder('—')->searchable(),
                TextColumn::make('vehicle_type')->label('Type')->badge()->color('gray'),
                TextColumn::make('pickup_address')->label('From → to')->limit(40)
                    ->state(fn (Trip $record) => ($record->pickup_address ?: 'Pin').' → '.($record->dropoff_address ?: 'Pin')),
                TextColumn::make('final_fare')->label('Fare')->alignEnd()
                    ->state(fn (Trip $record) => Ui::money($record->final_fare ?? $record->estimated_fare, $record->currency)),
                TextColumn::make('payment_method')->label('Pay')->badge()->color('gray')->formatStateUsing(fn ($state) => ucfirst($state->value)),
                TextColumn::make('payment_status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('requested_at')->label('Requested')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->multiple()->options(collect(TripStatus::cases())->mapWithKeys(fn ($c) => [$c->value => Ui::label($c)])),
                SelectFilter::make('payment_status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'waived' => 'Waived', 'refunded' => 'Refunded']),
                SelectFilter::make('service_area_id')->label('Area')->options(fn () => ServiceArea::query()->where('type', 'service')->pluck('name', 'id')),
                Filter::make('owed')->label('Money owed')->query(fn (Builder $query) => $query->where('amount_due', '>', 0)->where('payment_status', 'pending')),
                Filter::make('date')->schema([DatePicker::make('from')->native(false), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($query, $d) => $query->whereDate('requested_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($query, $d) => $query->whereDate('requested_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [OffersRelationManager::class, PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListTrips::route('/'), 'view' => ViewTrip::route('/{record}')];
    }
}
