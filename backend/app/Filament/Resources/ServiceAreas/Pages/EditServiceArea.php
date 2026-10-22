<?php

namespace App\Filament\Resources\ServiceAreas\Pages;

use App\Filament\Resources\ServiceAreas\ServiceAreaResource;
use App\Models\Trip;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceArea extends EditRecord
{
    protected static string $resource = ServiceAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn () => ! ($this->record->isService() && Trip::query()->where('service_area_id', $this->record->id)->exists())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['country_code', 'currency'] as $k) {
            if (! empty($data[$k])) {
                $data[$k] = strtoupper($data[$k]);
            }
        }

        return $data;
    }
}
