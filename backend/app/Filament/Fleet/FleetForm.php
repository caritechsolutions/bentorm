<?php

namespace App\Filament\Fleet;

use App\Enums\AreaType;
use App\Models\ServiceArea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/** Fleet details, shared by "Register your fleet" and "Fleet settings". */
final class FleetForm
{
    /** @return array<int, \Filament\Schemas\Components\Component> */
    public static function details(): array
    {
        return [
            Section::make('Your fleet')->columns(2)->schema([
                TextInput::make('name')->label('Fleet or company name')->required()->maxLength(120)
                    ->helperText('Drivers see this name on their invitation and on the cars.'),
                Select::make('service_area_id')->label('Where your cars work')->required()
                    ->options(fn () => ServiceArea::query()->where('type', AreaType::Service->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                TextInput::make('phone')->label('Contact phone')->tel()->required()->maxLength(32),
                TextInput::make('email')->label('Contact email')->email()->maxLength(255),
            ]),
        ];
    }

    /** Where Ride Coop pays the fleet's earnings (owners only, on Fleet settings). */
    public static function payouts(): Section
    {
        return Section::make('Payouts')->description('Ride Coop pays what your cars earn to this account by bank transfer. See Statement for what is owed.')
            ->columns(2)->collapsible()->schema([
                TextInput::make('bank_name')->label('Bank')->maxLength(80),
                TextInput::make('bank_branch')->label('Branch')->maxLength(80),
                TextInput::make('bank_account_name')->label('Name on the account')->maxLength(120),
                TextInput::make('bank_account_number')->label('Account number')->maxLength(40)->regex('/^[0-9 \-]{4,40}$/'),
            ]);
    }
}
