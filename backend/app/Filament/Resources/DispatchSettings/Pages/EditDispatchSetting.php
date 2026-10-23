<?php

namespace App\Filament\Resources\DispatchSettings\Pages;

use App\Filament\Resources\DispatchSettings\DispatchSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDispatchSetting extends EditRecord
{
    protected static string $resource = DispatchSettingResource::class;

    protected function getHeaderActions(): array
    {
        // The default can be changed but not removed; an area's own setting can be removed (it then uses the default).
        return [DeleteAction::make()->label('Remove (use the default)')->visible(fn () => ! $this->record->isDefault())];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->isDefault()) {
            $data['service_area_id'] = null;
        }

        return $data;
    }
}
