<?php

namespace App\Filament\Resources\SafetyAlerts;

use App\Filament\Resources\SafetyAlerts\Pages\ManageSafetyAlerts;
use App\Filament\Resources\Trips\TripResource;
use App\Models\SafetyAlert;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** SOS presses from the apps. Every admin gets a push + SMS; work them here. */
class SafetyAlertResource extends Resource
{
    protected static ?string $model = SafetyAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'SOS alerts';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $n = SafetyAlert::query()->where('status', '!=', 'resolved')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'open' => 'danger', 'handling' => 'warning', default => 'success' }),
                TextColumn::make('user.name')->label('Who')->description(fn (SafetyAlert $r) => ucfirst($r->role).' · '.$r->user?->phone)->searchable(),
                TextColumn::make('trip.public_id')->label('Trip')->placeholder('—')
                    ->url(fn (SafetyAlert $r) => $r->trip ? TripResource::getUrl('view', ['record' => $r->trip->public_id]) : null),
                TextColumn::make('where')->label('Where')->state(fn (SafetyAlert $r) => $r->lat !== null ? sprintf('%.5f, %.5f', $r->lat, $r->lng) : '—')
                    ->url(fn (SafetyAlert $r) => $r->lat !== null ? "https://maps.google.com/?q={$r->lat},{$r->lng}" : null, shouldOpenInNewTab: true),
                TextColumn::make('handler.name')->label('Handled by')->placeholder('—'),
                TextColumn::make('notes')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(['open' => 'Open', 'handling' => 'Handling', 'resolved' => 'Resolved'])])
            ->recordActions([
                Action::make('live')->label('Live trip')->icon(Heroicon::OutlinedMapPin)->color('gray')
                    ->visible(fn (SafetyAlert $r) => (bool) $r->trip?->share_token)
                    ->url(fn (SafetyAlert $r) => url('/t/'.$r->trip->share_token), shouldOpenInNewTab: true),
                Action::make('update')->label('Update')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (SafetyAlert $r) => ['status' => $r->status, 'notes' => $r->notes])
                    ->schema([
                        Select::make('status')->options(['open' => 'Open', 'handling' => 'Handling (I\'m on it)', 'resolved' => 'Resolved'])->required(),
                        Textarea::make('notes')->rows(3)->placeholder('Called the rider, police informed…'),
                    ])
                    ->action(fn (SafetyAlert $r, array $data) => $r->update($data + ['handled_by' => auth()->id(),
                        'resolved_at' => $data['status'] === 'resolved' ? now() : null])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSafetyAlerts::route('/')];
    }
}
