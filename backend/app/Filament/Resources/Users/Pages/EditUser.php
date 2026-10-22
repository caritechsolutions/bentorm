<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        if (! $this->record->is_active) {
            $this->record->tokens()->delete();
        }
        if ($this->record->isDriver()) {
            $this->record->driverProfile()->firstOrCreate([]);
        }
    }
}
