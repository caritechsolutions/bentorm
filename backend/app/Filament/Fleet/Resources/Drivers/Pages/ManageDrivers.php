<?php

namespace App\Filament\Fleet\Resources\Drivers\Pages;

use App\Filament\Fleet\Resources\Drivers\DriverResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDrivers extends ManageRecords
{
    protected static string $resource = DriverResource::class;

    public function getSubheading(): ?string
    {
        return DriverResource::fleet()->isApproved()
            ? 'Invite drivers by mobile number and choose which cars each may drive. One driver per car at a time.'
            : 'You can invite drivers once Ride Coop has approved your fleet.';
    }

    protected function getHeaderActions(): array
    {
        return [DriverResource::inviteAction()];
    }
}
