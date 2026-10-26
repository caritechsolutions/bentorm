<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Services\PaymentService;
use App\Services\TripService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewTrip extends ViewRecord
{
    protected static string $resource = TripResource::class;

    public function getTitle(): string
    {
        return 'Trip '.substr($this->record->public_id, -8);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')->label('Cancel trip')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (Trip $record) => $record->isActive())
                ->modalDescription('No cancellation fee is charged when an admin cancels. Any card hold is released.')
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(function (Trip $record, array $data): void {
                    app(TripService::class)->cancel($record, auth()->user(), $data['reason']);
                    Notification::make()->title('Trip cancelled')->success()->send();
                    $this->refreshFormData([]);
                }),
            Action::make('paidCash')->label('Mark paid in cash')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn (Trip $record) => $record->amount_due > 0 && $record->payment_status->value === 'pending')
                ->modalDescription(fn (Trip $record) => 'The rider paid '.$record->currency.' '.number_format((float) $record->amount_due, 2).' in cash. They can book again straight away.')
                ->schema([
                    \Filament\Forms\Components\Radio::make('paid_to')->label('Who took the money?')->required()->default('office')
                        ->options(['office' => 'Ride Coop (office)', 'driver' => 'The driver — their statement shows they hold it']),
                    Textarea::make('note')->maxLength(255),
                ])
                ->action(function (Trip $record, array $data): void {
                    app(PaymentService::class)->markPaidInCash($record, auth()->user(), $data['paid_to'], $data['note'] ?? null);
                    Notification::make()->title('Marked as paid in cash')->success()->send();
                    $this->refreshFormData([]);
                }),
            Action::make('waive')->label('Waive amount owed')->icon(Heroicon::OutlinedGift)->color('warning')
                ->visible(fn (Trip $record) => $record->amount_due > 0 && $record->payment_status->value === 'pending')
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(function (Trip $record, array $data): void {
                    app(PaymentService::class)->waive($record, auth()->user(), $data['reason']);
                    Notification::make()->title('Amount waived')->success()->send();
                }),
        ];
    }
}
