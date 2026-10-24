<?php

namespace App\Filament\Store\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Store\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $in = fn (array $s) => fn (Builder $query) => $query->whereIn('status', array_map(fn (OrderStatus $x) => $x->value, $s));

        return [
            'new' => Tab::make('New')->modifyQueryUsing($in([OrderStatus::Placed])),
            'preparing' => Tab::make('Preparing')->modifyQueryUsing($in([OrderStatus::Accepted])),
            'ready' => Tab::make('Ready')->modifyQueryUsing($in([OrderStatus::Ready])),
            'past' => Tab::make('Past')->modifyQueryUsing($in([OrderStatus::Declined, OrderStatus::Cancelled, OrderStatus::Expired])),
            'all' => Tab::make('All'),
        ];
    }
}
