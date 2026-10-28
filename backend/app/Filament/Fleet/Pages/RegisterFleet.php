<?php

namespace App\Filament\Fleet\Pages;

use App\Filament\Fleet\FleetForm;
use App\Models\Fleet;
use Filament\Facades\Filament;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** Step 2 of sign-up: the fleet itself. It starts "waiting for approval". */
class RegisterFleet extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register your fleet';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(FleetForm::details());
    }

    protected function handleRegistration(array $data): Model
    {
        $fleet = Fleet::query()->create($data);
        $fleet->users()->attach(Filament::auth()->id(), ['role' => 'owner']);

        return $fleet;
    }
}
