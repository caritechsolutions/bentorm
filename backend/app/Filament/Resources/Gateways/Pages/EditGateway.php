<?php

namespace App\Filament\Resources\Gateways\Pages;

use App\Filament\Resources\Gateways\GatewayResource;
use App\Plugins\PluginManager;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGateway extends EditRecord
{
    protected static string $resource = GatewayResource::class;

    protected function getHeaderActions(): array
    {
        return [GatewayResource::testAction(), DeleteAction::make()
            ->visible(fn () => ! \App\Models\Payment::query()->where('gateway_id', $this->record->id)->exists())];
    }

    /** Never send saved secrets to the browser. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['config'] = app(PluginManager::class)->maskedConfig($this->record);
        foreach ($data['config'] as $k => $v) {
            if ($v === PluginManager::MASK) {
                $data['config'][$k] = null;
            }
        }

        return $data;
    }

    /** Blank secrets keep the stored value. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['config'] = app(PluginManager::class)->prepareConfig($this->record->plugin, $data['config'] ?? [], $this->record->config ?? []);

        return $data;
    }
}
