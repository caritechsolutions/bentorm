<?php

namespace App\Filament\Store\Pages;

use App\Filament\Store\StoreForm;
use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** Store settings (owners): details, location, prep time, delivery distance, tax, hours. */
class EditStore extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Store settings';
    }

    public static function canView(Model $tenant): bool
    {
        return $tenant instanceof Store && (Filament::auth()->user()?->isStoreOwner($tenant) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([...StoreForm::details(), ...StoreForm::operations(), StoreForm::payouts()]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return StoreForm::cleanHours($data);
    }
}
