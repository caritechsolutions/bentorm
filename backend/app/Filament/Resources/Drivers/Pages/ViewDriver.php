<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Support\ApprovalActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    public function getTitle(): string
    {
        return $this->record->user?->name ?? 'Driver';
    }

    protected function getHeaderActions(): array
    {
        return [...ApprovalActions::forDriver(), EditAction::make()->color('gray')];
    }
}
