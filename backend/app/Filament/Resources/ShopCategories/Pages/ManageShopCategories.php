<?php

namespace App\Filament\Resources\ShopCategories\Pages;

use App\Filament\Resources\ShopCategories\ShopCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShopCategories extends ManageRecords
{
    protected static string $resource = ShopCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add category')];
    }
}
