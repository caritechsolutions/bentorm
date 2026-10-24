<?php

namespace App\Filament\Store\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Store\Resources\Orders\Pages\ListOrders;
use App\Filament\Store\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\Store;
use App\Services\OrderService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Incoming orders: accept (with prep time), decline, say what's out of stock, mark ready. */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationBadge(): ?string
    {
        $n = Order::query()->whereBelongsTo(Filament::getTenant(), 'store')->where('status', OrderStatus::Placed->value)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function money(Order $o, float $v): string
    {
        return $o->currency.' '.number_format($v, 2);
    }

    public static function statusColor(OrderStatus $s): string
    {
        return match ($s) { OrderStatus::Placed => 'danger', OrderStatus::Accepted => 'warning', OrderStatus::Ready => 'success', default => 'gray' };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items', 'customer'])->where('status', '!=', OrderStatus::PendingPayment->value))
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('id')->label('Order')->formatStateUsing(fn (Order $r) => $r->number())->weight('bold'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (OrderStatus $state) => $state->label())->color(fn (OrderStatus $state) => self::statusColor($state))
                    ->description(fn (Order $r) => match ($r->status) {
                        OrderStatus::Placed => 'Answer by '.$r->expires_at?->setTimezone($r->serviceArea?->timezone ?? config('app.timezone'))->format('g:i a'),
                        OrderStatus::Accepted => 'Ready by '.$r->ready_by?->setTimezone($r->serviceArea?->timezone ?? config('app.timezone'))->format('g:i a'),
                        default => null,
                    }),
                TextColumn::make('items_summary')->label('Items')->state(fn (Order $r) => $r->items->where('removed', false)->map(fn ($i) => "{$i->quantity}× {$i->name}")->implode(', '))->wrap()->limit(80),
                TextColumn::make('customer.name')->label('Customer')->formatStateUsing(fn (Order $r) => $r->customer?->firstName()),
                TextColumn::make('store_payout')->label('You receive')->formatStateUsing(fn (Order $r) => self::money($r, $r->store_payout)),
                TextColumn::make('placed_at')->label('Placed')->since(),
            ])
            ->recordActions([...self::actions(), ViewAction::make()->label('Open')])
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('New orders appear here with a sound. Keep this page open while your store is open.');
    }

    /** @return Action[] */
    public static function actions(): array
    {
        $svc = fn () => app(OrderService::class);
        $store = fn (): Store => Filament::getTenant();
        $done = fn (string $t) => Notification::make()->title($t)->success()->send();

        return [
            Action::make('accept')->label('Accept')->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (Order $record) => $record->status === OrderStatus::Placed)
                ->modalHeading(fn (Order $record) => 'Accept order '.$record->number())
                ->schema([TextInput::make('prep_minutes')->label('Ready in')->numeric()->minValue(1)->maxValue(240)->suffix('minutes')->required()
                    ->default(fn () => Filament::getTenant()?->currentPrepMinutes())])
                ->action(function (Order $record, array $data) use ($svc, $store, $done) {
                    $svc()->accept($record, $store(), (int) $data['prep_minutes']);
                    $done('Order accepted — the customer has been told.');
                }),
            Action::make('outOfStock')->label('Out of something')->icon(Heroicon::OutlinedExclamationTriangle)->color('warning')
                ->visible(fn (Order $record) => in_array($record->status, [OrderStatus::Placed, OrderStatus::Accepted], true) && ! $record->hasPendingChange() && $record->items->where('removed', false)->count() > 1)
                ->modalDescription('The customer is asked to accept the order without these. If they say no, the order is cancelled.')
                ->schema(fn (Order $record) => [
                    CheckboxList::make('item_ids')->label('Not available')->required()
                        ->options($record->items->where('removed', false)->mapWithKeys(fn ($i) => [$i->id => "{$i->quantity}× {$i->name}".($i->optionsText() ? " ({$i->optionsText()})" : '')])),
                    TextInput::make('note')->label('Message to the customer')->maxLength(255)->placeholder('Sorry, we just ran out.'),
                ])
                ->action(function (Order $record, array $data) use ($svc, $store, $done) {
                    $svc()->requestRemoval($record, $store(), array_map('intval', $data['item_ids']), $data['note'] ?? null);
                    $done('Sent to the customer to accept.');
                }),
            Action::make('ready')->label('Mark ready')->icon(Heroicon::OutlinedShoppingBag)->color('success')
                ->visible(fn (Order $record) => $record->status === OrderStatus::Accepted)
                ->disabled(fn (Order $record) => $record->hasPendingChange())
                ->requiresConfirmation()->modalDescription('Packed and ready for pickup? The customer\'s card is charged now.')
                ->action(function (Order $record) use ($svc, $store, $done) {
                    $svc()->markReady($record, $store());
                    $done('Marked ready.');
                }),
            Action::make('decline')->label('Decline')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (Order $record) => in_array($record->status, [OrderStatus::Placed, OrderStatus::Accepted], true))
                ->schema([
                    Select::make('reason')->label('Why')->required()->options([
                        'We are too busy right now.' => 'Too busy',
                        'Some items are not available.' => 'Items not available',
                        'We are closing.' => 'Closing',
                        'other' => 'Other',
                    ])->live(),
                    Textarea::make('other')->label('Reason')->rows(2)->maxLength(255)->visible(fn ($get) => $get('reason') === 'other')->required(fn ($get) => $get('reason') === 'other'),
                ])
                ->action(function (Order $record, array $data) use ($svc, $store, $done) {
                    $svc()->decline($record, $store(), $data['reason'] === 'other' ? (string) $data['other'] : $data['reason']);
                    $done('Order declined — the customer\'s card is released.');
                }),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Order $record) => 'Order '.$record->number())->columns(3)->columnSpanFull()->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn (OrderStatus $state) => $state->label())->color(fn (OrderStatus $state) => self::statusColor($state)),
                TextEntry::make('customer.name')->label('Customer')->formatStateUsing(fn (Order $record) => $record->customer?->firstName()),
                TextEntry::make('placed_at')->label('Placed')->since(),
                TextEntry::make('note')->label('Customer note')->placeholder('—')->columnSpanFull(),
                TextEntry::make('change_request')->label('Item change')->columnSpanFull()->visible(fn (Order $record) => (bool) $record->change_request)
                    ->state(fn (Order $record) => match ($record->change_request['status'] ?? null) {
                        'pending' => 'Waiting for the customer to accept the order without: '.collect($record->change_request['items'] ?? [])->map(fn ($i) => "{$i['quantity']}× {$i['name']}")->implode(', '),
                        'accepted' => 'Customer accepted the change.',
                        'declined' => 'Customer did not accept the change.',
                        default => null,
                    }),
                TextEntry::make('decline_reason')->label('Reason')->visible(fn (Order $record) => filled($record->decline_reason))->columnSpanFull(),
            ]),
            Section::make('Items')->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('name')->hiddenLabel()->weight('bold')->formatStateUsing(fn ($record) => "{$record->quantity}× {$record->name}".($record->removed ? ' (removed)' : '')),
                    TextEntry::make('options_text')->hiddenLabel()->state(fn ($record) => $record->optionsText() ?: null)->placeholder(''),
                    TextEntry::make('note')->hiddenLabel()->placeholder(''),
                    TextEntry::make('line_total')->hiddenLabel()->alignEnd()->formatStateUsing(fn ($record) => number_format($record->line_total, 2)),
                ]),
            ]),
            Section::make('Money')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('items_subtotal')->label('Items')->formatStateUsing(fn (Order $record) => self::money($record, $record->items_subtotal)),
                TextEntry::make('items_tax')->label('VAT on items')->formatStateUsing(fn (Order $record) => self::money($record, $record->items_tax)),
                TextEntry::make('commission')->label('Ride Coop commission')->formatStateUsing(fn (Order $record) => '− '.self::money($record, $record->commission)),
                TextEntry::make('store_payout')->label('You receive')->weight('bold')->formatStateUsing(fn (Order $record) => self::money($record, $record->store_payout)),
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
