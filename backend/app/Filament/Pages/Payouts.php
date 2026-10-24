<?php

namespace App\Filament\Pages;

use App\Models\DriverProfile;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Store;
use App\Models\User;
use App\Services\LedgerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Who Ride Coop owes (card rides, deliveries, tips, store orders) and who owes Ride Coop (commission on cash rides).
 * Pay by bank transfer as usual, then record it here; the driver/store sees it on their statement.
 */
class Payouts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Payouts';

    protected static ?string $title = 'Payouts';

    protected static ?string $slug = 'payouts';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.payouts';

    public string $tab = 'driver';

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        $rows = [];
        foreach (app(LedgerService::class)->balances($this->tab) as $b) {
            $rows[] = ['type' => $b->payee_type, 'id' => (int) $b->payee_id, 'balance' => (float) $b->balance, 'last_at' => $b->last_at] + $this->payee($b->payee_type, (int) $b->payee_id);
        }

        return $rows;
    }

    /** Name, phone and bank details for a driver (user id) or store. */
    private function payee(string $type, int $id): array
    {
        if ($type === 'store') {
            $s = Store::query()->find($id);

            return ['name' => $s?->name ?? 'Store #'.$id, 'phone' => $s?->phone, 'bank' => $s?->bank_account_number ? "{$s->bank_name} · {$s->bank_account_name} · {$s->bank_account_number}" : null];
        }
        $u = User::query()->with('driverProfile')->find($id);
        $p = $u?->driverProfile;

        return ['name' => $u?->name ?? 'Driver #'.$id, 'phone' => $u?->phone, 'bank' => $p?->bank_account_number ? "{$p->bank_name} · {$p->bank_account_name} · {$p->bank_account_number}" : null];
    }

    public function recordAction(): Action
    {
        return Action::make('record')
            ->label(fn (array $arguments) => ($arguments['balance'] ?? 0) >= 0 ? 'Record payout' : 'Record payment received')
            ->modalHeading(fn (array $arguments) => (($arguments['balance'] ?? 0) >= 0 ? 'Paid to ' : 'Received from ').($arguments['name'] ?? ''))
            ->fillForm(fn (array $arguments) => ['amount' => abs((float) ($arguments['balance'] ?? 0)), 'method' => 'bank_transfer'])
            ->schema([
                TextInput::make('amount')->numeric()->minValue(0.01)->required()->prefix('$'),
                Select::make('method')->options(['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'])->required(),
                TextInput::make('reference')->label('Reference (bank ref, receipt no.)')->maxLength(120),
                TextInput::make('note')->maxLength(200),
            ])
            ->action(function (array $data, array $arguments) {
                $sign = ($arguments['balance'] ?? 0) >= 0 ? 1 : -1;
                app(LedgerService::class)->recordPayout($arguments['type'], (int) $arguments['id'], $sign * (float) $data['amount'], $data['method'],
                    $data['reference'] ?? null, $data['note'] ?? null, auth()->user());
                Notification::make()->title('Recorded')->body('It shows on their statement.')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Download payout list (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn () => $this->csv()),
        ];
    }

    /** Everyone owed money, with bank details — for the bank's bulk transfer upload or to work through by hand. */
    public function csv(): StreamedResponse
    {
        $rows = array_filter($this->rows(), fn ($r) => $r['balance'] > 0);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Type', 'Name', 'Phone', 'Amount owed', 'Bank details']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['type'], $r['name'], $r['phone'], number_format($r['balance'], 2, '.', ''), $r['bank'] ?? 'MISSING']);
            }
            fclose($out);
        }, 'ridecoop-payouts-'.$this->tab.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<int, Payout> */
    public function recent(): array
    {
        return Payout::query()->where('payee_type', $this->tab)->latest('id')->limit(10)->get()->all();
    }
}
