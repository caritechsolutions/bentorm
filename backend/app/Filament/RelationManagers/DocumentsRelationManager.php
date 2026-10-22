<?php

namespace App\Filament\RelationManagers;

use App\Enums\DocumentStatus;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Ui;
use App\Models\Document;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Documents of a driver profile or a vehicle, with inline preview and review actions. */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('type'))
            ->defaultSort('id', 'desc')
            ->columns([
                ImageColumn::make('preview')
                    ->state(fn (Document $record) => $record->isImage() ? route('admin.document.file', $record) : null)
                    ->imageHeight(56)
                    ->url(fn (Document $record) => route('admin.document.file', $record), shouldOpenInNewTab: true),
                TextColumn::make('type.name')->label('Document')->weight('medium')
                    ->description(fn (Document $record) => $record->isImage() ? null : strtoupper(pathinfo((string) $record->original_name, PATHINFO_EXTENSION) ?: 'file')),
                TextColumn::make('number')->placeholder('—'),
                TextColumn::make('expires_at')->date()->placeholder('—')
                    ->color(fn (Document $record) => $record->expires_at?->isPast() ? 'danger' : ($record->expires_at?->lte(now()->addDays(30)) ? 'warning' : null)),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('review_note')->label('Note')->placeholder('—')->wrap()->limit(60),
                TextColumn::make('created_at')->label('Uploaded')->since(),
            ])
            ->filters([
                TernaryFilter::make('current')->label('Versions')
                    ->trueLabel('Include replaced uploads')->falseLabel('Current only')->default(false)
                    ->queries(
                        true: fn (Builder $query) => $query,
                        false: fn (Builder $query) => $query->where('status', '!=', DocumentStatus::Superseded->value),
                        blank: fn (Builder $query) => $query->where('status', '!=', DocumentStatus::Superseded->value),
                    ),
            ])
            ->recordActions([DocumentActions::open(), DocumentActions::approve(), DocumentActions::reject()])
            ->emptyStateHeading('No documents uploaded yet');
    }
}
