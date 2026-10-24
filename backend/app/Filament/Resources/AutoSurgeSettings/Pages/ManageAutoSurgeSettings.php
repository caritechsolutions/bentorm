<?php

namespace App\Filament\Resources\AutoSurgeSettings\Pages;

use App\Filament\Resources\AutoSurgeSettings\AutoSurgeSettingResource;
use App\Models\AutoSurgeSetting;
use App\Models\ServiceArea;
use Filament\Resources\Pages\ManageRecords;

class ManageAutoSurgeSettings extends ManageRecords
{
    protected static string $resource = AutoSurgeSettingResource::class;

    public function mount(): void
    {
        // One row per service area, so every area can be set up before the first minute runs.
        foreach (ServiceArea::query()->where('type', 'service')->pluck('id') as $id) {
            AutoSurgeSetting::query()->firstOrCreate(['service_area_id' => $id]);
        }
        parent::mount();
    }
}
