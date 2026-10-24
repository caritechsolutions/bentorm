<?php

namespace App\Filament\Store\Pages;

use App\Filament\Store\StoreForm;
use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** Step 2 of sign-up: the store itself. It starts "waiting for approval". */
class RegisterStore extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register your store';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(StoreForm::details());
    }

    protected function handleRegistration(array $data): Model
    {
        $store = Store::query()->create($data);
        $store->users()->attach(Filament::auth()->id(), ['role' => 'owner']);

        return $store;
    }
}
