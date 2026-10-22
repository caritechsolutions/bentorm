<?php

namespace App\Filament\Resources\VehicleTypes\Pages;

use App\Filament\Resources\VehicleTypes\VehicleTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVehicleTypes extends ManageRecords
{
    protected static string $resource = VehicleTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
