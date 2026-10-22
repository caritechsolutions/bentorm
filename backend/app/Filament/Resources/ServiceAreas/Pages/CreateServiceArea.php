<?php

namespace App\Filament\Resources\ServiceAreas\Pages;

use App\Filament\Resources\ServiceAreas\ServiceAreaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceArea extends CreateRecord
{
    protected static string $resource = ServiceAreaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['type'] ?? null) === 'service') {
            $data['parent_id'] = null;
        }
        foreach (['country_code', 'currency'] as $k) {
            if (! empty($data[$k])) {
                $data[$k] = strtoupper($data[$k]);
            }
        }

        return $data;
    }
}
