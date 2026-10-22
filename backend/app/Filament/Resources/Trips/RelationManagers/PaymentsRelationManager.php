<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\Ui;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function table(Table $table): Table
    {
        return PaymentResource::table($table)->defaultSort('id', 'asc');
    }
}
