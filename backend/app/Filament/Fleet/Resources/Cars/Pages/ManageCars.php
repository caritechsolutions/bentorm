<?php

namespace App\Filament\Fleet\Resources\Cars\Pages;

use App\Enums\VehicleStatus;
use App\Filament\Fleet\Resources\Cars\CarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCars extends ManageRecords
{
    protected static string $resource = CarResource::class;

    protected ?string $subheading = 'Add each car, then upload its documents (Documents). Ride Coop checks the car before it can be driven.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a car')->mutateDataUsing(fn (array $data) => $data + ['status' => VehicleStatus::Pending->value])];
    }
}
