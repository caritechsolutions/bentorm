<?php

namespace App\Filament\Resources\Gateways\Pages;

use App\Filament\Resources\Gateways\GatewayResource;
use App\Plugins\PluginManager;
use Filament\Resources\Pages\CreateRecord;

class CreateGateway extends CreateRecord
{
    protected static string $resource = GatewayResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $plugins = app(PluginManager::class);
        $data['type'] = $plugins->classFor($data['plugin'])::type();
        $data['config'] = $plugins->prepareConfig($data['plugin'], $data['config'] ?? []);

        return $data;
    }
}
