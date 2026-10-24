<?php

namespace App\Filament\Store\Pages;

use App\Models\LedgerEntry;
use App\Models\Store;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** What Ride Coop owes this store for delivered orders, and the payouts made. */
class Statement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Statement';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.store.statement';

    public static function canAccess(): bool
    {
        $t = Filament::getTenant();

        return $t instanceof Store && (Filament::auth()->user()?->isStoreOwner($t) ?? false);
    }

    public function balance(): float
    {
        return LedgerEntry::balance('store', Filament::getTenant()->id);
    }

    public function entries()
    {
        return LedgerEntry::query()->for('store', Filament::getTenant()->id)->latest('id')->limit(100)->get();
    }
}
