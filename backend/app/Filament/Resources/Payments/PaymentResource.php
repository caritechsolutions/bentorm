<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Trips\TripResource;
use App\Filament\Support\Ui;
use App\Models\Payment;
use App\Services\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Riders & payments';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['trip', 'user', 'gateway', 'paymentMethod']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M H:i')->sortable(),
                TextColumn::make('purpose')->badge()->color('gray')->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('method')->formatStateUsing(fn ($state, Payment $record) => ucfirst($state).($record->paymentMethod ? " ••{$record->paymentMethod->last4}" : '')),
                TextColumn::make('amount')->alignEnd()->state(fn (Payment $record) => Ui::money($record->amount, $record->currency))
                    ->description(fn (Payment $record) => $record->captured_amount !== null && $record->captured_amount != $record->amount ? 'captured '.number_format($record->captured_amount, 2) : null),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('user.name')->label('Rider')->searchable(),
                TextColumn::make('trip.public_id')->label('Trip')->formatStateUsing(fn ($state) => substr((string) $state, -8))->fontFamily('mono')
                    ->url(fn (Payment $record) => $record->trip ? TripResource::getUrl('view', ['record' => $record->trip]) : null),
                TextColumn::make('gateway.name')->label('Gateway')->placeholder('—')->toggleable(),
                TextColumn::make('gateway_ref')->label('Gateway ref')->placeholder('—')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failure_reason')->label('Failure')->placeholder('—')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'authorized' => 'Authorised (hold)', 'captured' => 'Captured', 'voided' => 'Voided', 'failed' => 'Failed', 'refunded' => 'Refunded']),
                SelectFilter::make('purpose')->options(['hold' => 'Hold', 'trip_fare' => 'Trip fare', 'top_up' => 'Top-up', 'cancellation_fee' => 'Cancellation fee']),
                SelectFilter::make('method')->options(['cash' => 'Cash', 'card' => 'Card']),
            ])
            ->recordActions([
                Action::make('refund')->label('Refund')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                    ->visible(fn (Payment $record) => $record->status === 'captured' && $record->method === 'card')
                    ->schema(fn (Payment $record) => [TextInput::make('amount')->numeric()->required()
                        ->default($record->captured_amount ?? $record->amount)->maxValue($record->captured_amount ?? $record->amount)->prefix($record->currency)])
                    ->requiresConfirmation()
                    ->action(function (Payment $record, array $data): void {
                        app(PaymentService::class)->refund($record->load('gateway'), (float) $data['amount']);
                        Notification::make()->title('Refunded')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
