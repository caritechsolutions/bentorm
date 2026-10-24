<?php

namespace App\Filament\Resources\Stores\Pages;

use App\Filament\Resources\Stores\StoreResource;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    /** commission_rate is not mass-assignable on Store (stores can't set their own); the admin form saves it here. */
    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $record->forceFill(['commission_rate' => $data['commission_rate'] ?? null]);
        unset($data['commission_rate']);
        $record->fill($data)->save();

        return $record;
    }
}
