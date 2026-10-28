<?php

namespace App\Filament\Resources\Fleets\Pages;

use App\Filament\Resources\Fleets\FleetResource;
use Filament\Resources\Pages\ListRecords;

class ListFleets extends ListRecords
{
    protected static string $resource = FleetResource::class;

    protected ?string $subheading = 'Taxi owners sign up in the fleet portal (/fleet). Approve the fleet, then each car under Vehicles.';
}
