<?php

namespace App\Filament\Resources\PromoCodes;

use App\Filament\Resources\PromoCodes\Pages\ManagePromoCodes;
use App\Models\PromoCode;
use App\Models\ServiceArea;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Promo codes customers type at booking or checkout. Ride Coop pays the discount; drivers and stores get full price. */
class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Promo codes';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->required()->maxLength(32)->unique(ignoreRecord: true)->placeholder('WELCOME10')
                ->helperText('Letters and numbers; customers can type it in any case.'),
            TextInput::make('description')->maxLength(255)->placeholder('10% off your first ride'),
            Select::make('kind')->options(['percent' => 'Percent off', 'amount' => 'Amount off'])->default('percent')->required()->live(),
            TextInput::make('value')->numeric()->minValue(0.01)->required()->suffix(fn ($get) => $get('kind') === 'percent' ? '%' : '$'),
            TextInput::make('max_discount')->label('Most it can take off')->numeric()->minValue(0)->prefix('$')->visible(fn ($get) => $get('kind') === 'percent'),
            Select::make('applies_to')->options(['both' => 'Rides and shop', 'rides' => 'Rides only', 'shop' => 'Shop only'])->default('both')->required(),
            TextInput::make('min_spend')->label('Minimum spend')->numeric()->minValue(0)->default(0)->prefix('$'),
            Select::make('service_area_id')->label('Area')->placeholder('All areas')
                ->options(fn () => ServiceArea::query()->where('type', 'service')->pluck('name', 'id')),
            DateTimePicker::make('starts_at')->native(false)->placeholder('Now'),
            DateTimePicker::make('ends_at')->native(false)->placeholder('No end'),
            TextInput::make('max_uses')->label('Total uses')->numeric()->minValue(1)->placeholder('Unlimited'),
            TextInput::make('max_uses_per_user')->label('Uses per customer')->numeric()->minValue(1)->default(1)->required(),
            Toggle::make('first_order_only')->label('New customers only (first ride or order)'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->weight('bold')->searchable()->copyable()->description(fn (PromoCode $r) => $r->description),
                TextColumn::make('summary')->label('Discount')->state(fn (PromoCode $r) => $r->summary()),
                TextColumn::make('applies_to')->label('For')->formatStateUsing(fn ($state) => ['both' => 'Rides & shop', 'rides' => 'Rides', 'shop' => 'Shop'][$state] ?? $state),
                TextColumn::make('used')->label('Used')->state(fn (PromoCode $r) => $r->redemptions()->where('status', 'used')->count().($r->max_uses ? ' / '.$r->max_uses : '')),
                TextColumn::make('given')->label('Given away')->state(fn (PromoCode $r) => 'BBD '.number_format((float) $r->redemptions()->where('status', 'used')->sum('amount'), 2)),
                TextColumn::make('ends_at')->label('Ends')->date()->placeholder('—'),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePromoCodes::route('/')];
    }
}
