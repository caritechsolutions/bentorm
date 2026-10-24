<?php

namespace App\Filament\Store\Resources\Orders\Pages;

use App\Filament\Store\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Order '.$this->record->number();
    }

    protected function getHeaderActions(): array
    {
        return OrderResource::actions();
    }
}
