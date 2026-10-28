<?php

namespace App\Filament\Fleet\Pages;

use App\Models\Fleet;
use App\Models\FleetCash;
use App\Models\FleetDriver;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\FleetService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * What Ride Coop owes the fleet (card trips, fees, tips) less commission on cash trips, the payouts made, and the
 * cash each driver collected in the fleet's cars and still has to hand over.
 */
class Statement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Statement';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.fleet.statement';

    public static function canAccess(): bool
    {
        $t = Filament::getTenant();

        return $t instanceof Fleet && (Filament::auth()->user()?->isFleetOwner($t) ?? false);
    }

    public function fleet(): Fleet
    {
        return Filament::getTenant();
    }

    public function balance(): float
    {
        return LedgerEntry::balance('fleet', $this->fleet()->id);
    }

    public function entries()
    {
        return LedgerEntry::query()->for('fleet', $this->fleet()->id)->latest('id')->limit(100)->get();
    }

    /** @return array<int, array{driver_id: int, name: string, held: float}> */
    public function cash(): array
    {
        $names = FleetDriver::query()->where('fleet_id', $this->fleet()->id)->whereNotNull('driver_id')->with('driver')->get()
            ->mapWithKeys(fn ($d) => [$d->driver_id => $d->displayName()]);
        $rows = [];
        foreach ($this->fleet()->cashHeld() as $id => $held) {
            $rows[] = ['driver_id' => (int) $id, 'name' => $names[$id] ?? (User::query()->find($id)?->name ?? 'Driver'), 'held' => $held];
        }
        usort($rows, fn ($a, $b) => $b['held'] <=> $a['held']);

        return $rows;
    }

    public function cashEntries()
    {
        return FleetCash::query()->where('fleet_id', $this->fleet()->id)->with('driver')->latest('id')->limit(50)->get();
    }

    public function receivedAction(): Action
    {
        return Action::make('received')->label('Record cash received')->size('sm')
            ->modalHeading(fn (array $arguments) => 'Cash received from '.($arguments['name'] ?? 'driver'))
            ->fillForm(fn (array $arguments) => ['amount' => max(0, (float) ($arguments['held'] ?? 0))])
            ->schema([
                TextInput::make('amount')->numeric()->minValue(0.01)->required()->prefix('$'),
                TextInput::make('note')->maxLength(120)->placeholder('e.g. Friday handover'),
            ])
            ->action(function (array $data, array $arguments) {
                $driverId = (int) ($arguments['driver_id'] ?? 0);
                abort_unless(FleetCash::query()->where('fleet_id', $this->fleet()->id)->where('driver_id', $driverId)->exists(), 404);
                app(FleetService::class)->recordCashReceived($this->fleet(), $driverId, (float) $data['amount'], $data['note'] ?? null, Filament::auth()->user());
                Notification::make()->title('Recorded')->success()->send();
            });
    }
}
