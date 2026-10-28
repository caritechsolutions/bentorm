<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Support\ApprovalActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVehicle extends ViewRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [...ApprovalActions::forVehicle(), VehicleResource::moveToFleetAction(), EditAction::make()->color('gray')];
    }
}
