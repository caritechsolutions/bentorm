<?php

namespace App\Filament\Support;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\DocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Open / approve / reject actions for a document row, shared by the review queue and relation managers. */
final class DocumentActions
{
    public static function open(): Action
    {
        return Action::make('open')
            ->label('Open')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Document $record) => route('admin.document.file', $record), shouldOpenInNewTab: true);
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Document $record) => in_array($record->status, [DocumentStatus::Pending, DocumentStatus::Rejected], true))
            ->modalHeading(fn (Document $record) => "Approve {$record->type?->name}")
            ->schema(fn (Document $record) => $record->type?->requires_expiry ? [
                DatePicker::make('expires_at')->label('Expiry date')->required()->default($record->expires_at)->native(false),
            ] : [])
            ->action(function (Document $record, array $data): void {
                app(DocumentService::class)->review($record->load('type'), true, null, auth()->user(), $data['expires_at'] ?? null);
                Notification::make()->title("{$record->type?->name} approved")->success()->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Document $record) => in_array($record->status, [DocumentStatus::Pending, DocumentStatus::Approved], true))
            ->modalHeading(fn (Document $record) => "Reject {$record->type?->name}")
            ->modalDescription('The driver sees this reason and is asked to upload a new document.')
            ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(255)])
            ->action(function (Document $record, array $data): void {
                app(DocumentService::class)->review($record->load('type'), false, $data['note'], auth()->user());
                Notification::make()->title("{$record->type?->name} rejected")->warning()->send();
            });
    }
}
