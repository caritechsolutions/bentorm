<?php

namespace App\Filament\Fleet\Resources\Team\Pages;

use App\Filament\Fleet\Resources\Team\TeamResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageTeam extends ManageRecords
{
    protected static string $resource = TeamResource::class;

    protected ?string $subheading = 'People who can log in to this fleet portal. New people set their password with "Forgot password" on the login page.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a person')->using(fn (array $data) => TeamResource::addMember(Filament::getTenant(), $data))];
    }
}
