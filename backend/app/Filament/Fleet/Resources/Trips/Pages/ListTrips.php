<?php

namespace App\Filament\Fleet\Resources\Trips\Pages;

use App\Filament\Fleet\Resources\Trips\TripResource;
use Filament\Resources\Pages\ListRecords;

class ListTrips extends ListRecords
{
    protected static string $resource = TripResource::class;

    protected ?string $subheading = 'Filter by car or driver. "You earn" is the fleet\'s share after Ride Coop\'s commission; cash trips were paid to the driver (see Statement).';
}
