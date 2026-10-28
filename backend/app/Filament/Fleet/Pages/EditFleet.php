<?php

namespace App\Filament\Fleet\Pages;

use App\Filament\Fleet\FleetForm;
use App\Models\Fleet;
use Filament\Facades\Filament;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** Fleet settings (owners): details and the bank account payouts go to. */
class EditFleet extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Fleet settings';
    }

    public static function canView(Model $tenant): bool
    {
        return $tenant instanceof Fleet && (Filament::auth()->user()?->isFleetOwner($tenant) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([...FleetForm::details(), FleetForm::payouts()]);
    }
}
