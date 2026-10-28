<?php

namespace App\Filament\Resources\Fleets;

use App\Enums\FleetStatus;
use App\Filament\Resources\Fleets\Pages\EditFleet;
use App\Filament\Resources\Fleets\Pages\ListFleets;
use App\Filament\Resources\Fleets\Pages\ViewFleet;
use App\Filament\Resources\Fleets\RelationManagers\CarsRelationManager;
use App\Filament\Resources\Fleets\RelationManagers\DriversRelationManager;
use App\Models\Fleet;
use App\Models\LedgerEntry;
use App\Models\ServiceArea;
use App\Services\FleetService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Taxi owners' fleets (signed up in the fleet portal, /fleet): approve them, see their cars, drivers and money. */
class FleetResource extends Resource
{
    protected static ?string $model = Fleet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Drivers';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationBadge(): ?string
    {
        $n = Fleet::query()->where('status', FleetStatus::Pending->value)->count();

        return $n ? (string) $n : null;
    }

    private static function color(FleetStatus $s): string
    {
        return match ($s) { FleetStatus::Approved => 'success', FleetStatus::Pending => 'warning', default => 'danger' };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Fleet')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120),
                Select::make('service_area_id')->label('Service area')->options(fn () => ServiceArea::query()->where('type', 'service')->pluck('name', 'id')),
                TextInput::make('phone')->tel()->maxLength(32),
                TextInput::make('email')->email()->maxLength(255),
            ]),
            Section::make('Money')->schema([
                \Filament\Forms\Components\Toggle::make('tips_to_driver')->label('Tips go to the driver')
                    ->helperText('On: riders\' tips on trips in this fleet\'s cars are paid to the driver. Off: the fleet keeps them. Applies to tips from now on.')
                    ->default(true),
            ]),
            Section::make('Payouts')->columns(2)->schema([
                TextInput::make('bank_name')->maxLength(80),
                TextInput::make('bank_branch')->maxLength(80),
                TextInput::make('bank_account_name')->maxLength(120),
                TextInput::make('bank_account_number')->maxLength(40),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Fleet')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('name')->weight('bold'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (FleetStatus $state) => $state->label())->color(fn (FleetStatus $state) => self::color($state)),
                TextEntry::make('review_note')->label('Note')->placeholder('—'),
                TextEntry::make('serviceArea.name')->label('Service area')->placeholder('—'),
                TextEntry::make('owners')->label('Owner(s)')->state(fn (Fleet $record) => $record->users()->wherePivot('role', 'owner')->get()->map(fn ($u) => "{$u->name} · {$u->phone}")->implode(', ') ?: '—'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('bank')->label('Bank')->state(fn (Fleet $record) => $record->bank_account_number ? "{$record->bank_name} · {$record->bank_account_name} · {$record->bank_account_number}" : 'Not given'),
                TextEntry::make('balance')->label('Ride Coop owes them')->state(fn (Fleet $record) => number_format(LedgerEntry::balance('fleet', $record->id), 2))
                    ->url(fn () => \App\Filament\Pages\Payouts::getUrl(['tab' => 'fleet'])),
                TextEntry::make('tips_to_driver')->label('Tips')->formatStateUsing(fn ($state) => $state ? 'Go to the driver' : 'Kept by the fleet'),
                TextEntry::make('cash')->label('Cash held by drivers')->state(fn (Fleet $record) => number_format(array_sum($record->cashHeld()), 2)),
                TextEntry::make('today')->label('Today')->state(fn (Fleet $record) => ($t = app(FleetService::class)->today($record))['trips'].' trips · '.number_format($t['earnings'], 2)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['vehicles', 'drivers as active_drivers_count' => fn ($w) => $w->where('status', 'active')]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium')->description(fn (Fleet $r) => $r->phone),
                TextColumn::make('status')->badge()->formatStateUsing(fn (FleetStatus $state) => $state->label())->color(fn (FleetStatus $state) => self::color($state)),
                TextColumn::make('vehicles_count')->label('Cars'),
                TextColumn::make('active_drivers_count')->label('Drivers'),
                TextColumn::make('created_at')->label('Signed up')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(collect(FleetStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))])
            ->recordActions([ViewAction::make()]);
    }

    /** @return Action[] */
    public static function approvalActions(): array
    {
        return [
            Action::make('approve')->label('Approve fleet')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (Fleet $record) => $record->status !== FleetStatus::Approved)
                ->requiresConfirmation()
                ->modalDescription('Each car is still checked on its own (Vehicles) before it can be driven.')
                ->action(function (Fleet $record): void {
                    $record->forceFill(['status' => FleetStatus::Approved, 'review_note' => null, 'approved_at' => now()])->save();
                    Notification::make()->title("{$record->name} approved")->success()->send();
                }),
            self::statusAction('suspend', 'Suspend', 'warning', Heroicon::OutlinedPauseCircle, FleetStatus::Suspended),
            self::statusAction('reject', 'Reject', 'danger', Heroicon::OutlinedNoSymbol, FleetStatus::Rejected),
        ];
    }

    private static function statusAction(string $name, string $label, string $color, $icon, FleetStatus $to): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(fn (Fleet $record) => $record->status !== $to && ($to !== FleetStatus::Suspended || $record->status === FleetStatus::Approved))
            ->modalDescription('Drivers in the fleet\'s cars finish any trip, then go offline.')
            ->schema([Textarea::make('note')->label('Reason (shown to the fleet)')->required()->maxLength(255)])
            ->action(function (Fleet $record, array $data) use ($to): void {
                $record->forceFill(['status' => $to, 'review_note' => $data['note']])->save();
                app(FleetService::class)->offTheRoad($record, "{$record->name} is ".strtolower($to->label()).' on Ride Coop.');
                Notification::make()->title("{$record->name}: ".strtolower($to->label()))->warning()->send();
            });
    }

    public static function getRelations(): array
    {
        return [CarsRelationManager::class, DriversRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFleets::route('/'),
            'view' => ViewFleet::route('/{record}'),
            'edit' => EditFleet::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // owners sign up in the fleet portal
    }
}
