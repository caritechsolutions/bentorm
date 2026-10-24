<?php

namespace App\Filament\Store\Resources\Staff\Pages;

use App\Filament\Store\Resources\Staff\StaffResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageStaff extends ManageRecords
{
    protected static string $resource = StaffResource::class;

    protected ?string $subheading = 'People who can log in to this store. New people set their password with "Forgot password" on the login page.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a person')->using(fn (array $data) => StaffResource::addMember(Filament::getTenant(), $data))];
    }
}
