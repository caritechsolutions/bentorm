<?php

namespace App\Filament\Resources\DispatchSettings\Pages;

use App\Filament\Resources\DispatchSettings\DispatchSettingResource;
use App\Models\DispatchSetting;
use Filament\Resources\Pages\CreateRecord;

class CreateDispatchSetting extends CreateRecord
{
    protected static string $resource = DispatchSettingResource::class;

    /** Start from the default's values so an area override only needs the changes. */
    protected function fillForm(): void
    {
        $default = DispatchSetting::forArea(null);
        $this->form->fill(collect($default->only($default->getFillable()))->except('service_area_id')->all());
    }
}
