<?php

namespace App\Filament\Store\Resources\MenuSections\Pages;

use App\Filament\Store\Resources\MenuSections\MenuSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMenuSections extends ManageRecords
{
    protected static string $resource = MenuSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add section')];
    }
}
