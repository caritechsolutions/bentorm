<?php

namespace App\Filament\Fleet\Resources\Drivers;

use App\Filament\Fleet\Resources\Drivers\Pages\ManageDrivers;
use App\Models\Fleet;
use App\Models\FleetDriver;
use App\Services\FleetService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Drivers of the fleet: invite by mobile number (they accept in the Ride Coop Driver app), choose which cars each
 * may drive, and take access away at any time — a driver on a trip finishes it, then goes offline.
 */
class DriverResource extends Resource
{
    protected static ?string $model = FleetDriver::class;

    protected static ?string $tenantRelationshipName = 'drivers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Drivers';

    protected static ?string $modelLabel = 'driver';

    protected static ?string $slug = 'drivers';

    protected static ?int $navigationSort = 2;

    public static function fleet(): Fleet
    {
        return Filament::getTenant();
    }

    /** @return array<int, string> car id => "PLATE · Make Model" */
    public static function carOptions(): array
    {
        return self::fleet()->vehicles()->orderBy('plate')->get()->mapWithKeys(fn ($v) => [$v->id => "{$v->plate} · {$v->make} {$v->model}"])->all();
    }

    public static function table(Table $table): Table
    {
        $held = self::fleet()->cashHeld();
        $driving = collect(app(FleetService::class)->onTheRoad(self::fleet()))->mapWithKeys(fn ($u, $vid) => [$u->id => $vid])->all();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['driver', 'vehicles'])->orderByRaw("case status when 'active' then 0 when 'invited' then 1 else 2 end")->orderBy('id'))
            ->columns([
                TextColumn::make('name')->label('Driver')->weight('medium')->state(fn (FleetDriver $r) => $r->displayName())
                    ->description(fn (FleetDriver $r) => $r->phone)->searchable(['name', 'phone']),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) { 'invited' => 'Invited — waiting', 'active' => 'Active', 'declined' => 'Declined', 'removed' => 'Removed', default => $state })
                    ->color(fn ($state) => match ($state) { 'active' => 'success', 'invited' => 'warning', default => 'gray' }),
                TextColumn::make('cars')->label('May drive')->state(fn (FleetDriver $r) => $r->vehicles->pluck('plate')->join(', ') ?: '—')->wrap(),
                TextColumn::make('driving')->label('Driving now')->state(function (FleetDriver $r) use ($driving) {
                    $vid = $r->driver_id ? ($driving[$r->driver_id] ?? null) : null;

                    return $vid ? ($r->vehicles->firstWhere('id', $vid)?->plate ?? 'a car') : '—';
                }),
                TextColumn::make('cash')->label('Cash held')->state(fn (FleetDriver $r) => number_format($held[$r->driver_id] ?? 0, 2))
                    ->color(fn (FleetDriver $r) => ($held[$r->driver_id] ?? 0) > 0 ? 'warning' : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'invited' => 'Invited', 'declined' => 'Declined', 'removed' => 'Removed']),
            ])
            ->recordActions([
                Action::make('cars')->label('Cars')->icon(Heroicon::OutlinedTruck)->color('gray')
                    ->visible(fn (FleetDriver $r) => in_array($r->status, [FleetDriver::ACTIVE, FleetDriver::INVITED], true))
                    ->modalHeading(fn (FleetDriver $r) => 'Cars '.$r->displayName().' may drive')
                    ->modalDescription('Taking away the car they are driving sets them offline (after their current trip).')
                    ->fillForm(fn (FleetDriver $r) => ['vehicles' => $r->vehicles->pluck('id')->all()])
                    ->schema([CheckboxList::make('vehicles')->label('Cars')->options(fn () => self::carOptions())->columns(2)])
                    ->action(function (FleetDriver $r, array $data) {
                        app(FleetService::class)->setVehicles($r, $data['vehicles'] ?? []);
                        Notification::make()->title('Saved')->success()->send();
                    }),
                Action::make('remove')->label(fn (FleetDriver $r) => $r->status === FleetDriver::INVITED ? 'Withdraw invitation' : 'Remove')
                    ->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (FleetDriver $r) => in_array($r->status, [FleetDriver::ACTIVE, FleetDriver::INVITED], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (FleetDriver $r) => 'Remove '.$r->displayName().'?')
                    ->modalDescription('They lose access to all your cars now. If they are on a trip, they finish it and then go offline.')
                    ->action(function (FleetDriver $r) {
                        app(FleetService::class)->remove($r);
                        Notification::make()->title('Removed')->success()->send();
                    }),
            ]);
    }

    public static function inviteAction(): Action
    {
        return Action::make('invite')->label('Invite a driver')->icon(Heroicon::OutlinedUserPlus)
            ->modalDescription('They get the invitation in the Ride Coop Driver app (if they are new, once they sign up with this number). Their own driver documents stay with them.')
            ->schema([
                TextInput::make('phone')->label('Mobile number')->tel()->required(),
                TextInput::make('name')->label('Name')->maxLength(120)->helperText('So you know who it is before they accept.'),
                CheckboxList::make('vehicles')->label('Cars they may drive')->options(fn () => self::carOptions())->columns(2),
            ])
            ->action(function (array $data) {
                try {
                    app(FleetService::class)->invite(self::fleet(), (string) $data['phone'], $data['name'] ?? null, $data['vehicles'] ?? [], Filament::auth()->user());
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['mountedActions.0.data.phone' => collect($e->errors())->flatten()->first()]);
                }
                Notification::make()->title('Invitation sent')->success()->send();
            })
            ->visible(fn () => self::fleet()->isApproved());
    }

    public static function getPages(): array
    {
        return ['index' => ManageDrivers::route('/')];
    }

    public static function canCreate(): bool
    {
        return false; // "Invite a driver" instead
    }
}
