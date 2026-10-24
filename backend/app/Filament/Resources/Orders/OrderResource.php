<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Stores\StoreResource;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Every shop order, read-only: what was ordered, the money split, what happened. */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 0;

    private static function money(Order $o, ?float $v): string
    {
        return $o->currency.' '.number_format((float) $v, 2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['store', 'customer']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('Order')->formatStateUsing(fn (Order $r) => $r->number())->weight('bold'),
                TextColumn::make('store.name')->label('Store')->searchable(),
                TextColumn::make('customer.name')->label('Customer')->searchable()->description(fn (Order $r) => $r->customer?->phone),
                TextColumn::make('status')->badge()->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => match ($state) { OrderStatus::Ready => 'success', OrderStatus::Placed, OrderStatus::Accepted => 'warning', OrderStatus::PendingPayment => 'gray', default => 'danger' }),
                TextColumn::make('total')->formatStateUsing(fn (Order $r) => self::money($r, $r->total))->sortable(),
                TextColumn::make('payment_status')->label('Payment')->badge()->color('gray'),
                TextColumn::make('created_at')->label('Ordered')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                SelectFilter::make('store_id')->label('Store')->relationship('store', 'name')->searchable(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('number')->state(fn (Order $record) => $record->number())->weight('bold'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (OrderStatus $state) => $state->label()),
                TextEntry::make('store.name')->label('Store')->url(fn (Order $record) => StoreResource::getUrl('view', ['record' => $record->store_id])),
                TextEntry::make('customer.name')->label('Customer')->formatStateUsing(fn (Order $record) => "{$record->customer?->name} · {$record->customer?->phone}"),
                TextEntry::make('dropoff_address')->label('Deliver to')->placeholder('—')->columnSpan(2)
                    ->url(fn (Order $record) => "https://www.google.com/maps?q={$record->dropoff_lat},{$record->dropoff_lng}", shouldOpenInNewTab: true),
                TextEntry::make('distance_m')->label('Distance')->formatStateUsing(fn ($state) => number_format($state / 1000, 1).' km'),
                TextEntry::make('prep_minutes')->label('Prep time')->suffix(' min')->placeholder('—'),
                TextEntry::make('note')->label('Customer note')->placeholder('—')->columnSpanFull(),
                TextEntry::make('decline_reason')->label('Reason')->placeholder('—')->columnSpanFull()->visible(fn (Order $record) => filled($record->decline_reason)),
            ]),
            Section::make('Items')->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('name')->hiddenLabel()->formatStateUsing(fn ($record) => "{$record->quantity}× {$record->name}".($record->removed ? ' (removed)' : '')),
                    TextEntry::make('options_text')->hiddenLabel()->state(fn ($record) => $record->optionsText() ?: null)->placeholder(''),
                    TextEntry::make('line_total')->hiddenLabel()->alignEnd()->formatStateUsing(fn ($record) => number_format($record->line_total, 2)),
                ]),
            ]),
            Section::make('Money')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('items_subtotal')->label('Items')->formatStateUsing(fn (Order $record) => self::money($record, $record->items_subtotal)),
                TextEntry::make('items_tax')->label('VAT on items')->formatStateUsing(fn (Order $record) => self::money($record, $record->items_tax)),
                TextEntry::make('delivery_fee')->label('Delivery (incl. VAT)')->formatStateUsing(fn (Order $record) => self::money($record, $record->delivery_fee)),
                TextEntry::make('service_fee')->label('Service fee')->formatStateUsing(fn (Order $record) => self::money($record, $record->service_fee)),
                TextEntry::make('total')->label('Customer pays')->weight('bold')->formatStateUsing(fn (Order $record) => self::money($record, $record->total)),
                TextEntry::make('store_payout')->label('Store receives')->formatStateUsing(fn (Order $record) => self::money($record, $record->store_payout)),
                TextEntry::make('commission')->label('Commission')->formatStateUsing(fn (Order $record) => self::money($record, $record->commission)),
                TextEntry::make('delivery_platform_share')->label('Delivery share / driver')->formatStateUsing(fn (Order $record) => self::money($record, $record->delivery_platform_share).' / '.self::money($record, $record->driver_earnings)),
                TextEntry::make('payment_status')->label('Payment')->badge(),
            ]),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'view' => ViewOrder::route('/{record}')];
    }
}
