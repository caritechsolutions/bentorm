<?php

namespace App\Filament\Resources\DispatchSettings\Pages;

use App\Filament\Resources\DispatchSettings\DispatchSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDispatchSettings extends ListRecords
{
    protected static string $resource = DispatchSettingResource::class;

    protected ?string $subheading = 'How ride requests reach drivers. The default applies everywhere unless an area has its own.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a setting for an area')];
    }
}
