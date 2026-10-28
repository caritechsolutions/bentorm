<?php

namespace App\Filament\Resources\Fleets\Pages;

use App\Filament\Resources\Fleets\FleetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFleet extends ViewRecord
{
    protected static string $resource = FleetResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [...FleetResource::approvalActions(), EditAction::make()->color('gray')];
    }
}
