<?php

namespace App\Filament\Store\Resources\Products\Pages;

use App\Filament\Store\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProducts extends ManageRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add product')];
    }
}
