<?php

namespace App\Filament\Resources\Documents;

use App\Enums\DocumentStatus;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\Ui;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use App\Services\DocumentService;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/** The document review queue. */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Drivers';

    protected static ?string $navigationLabel = 'Document review';

    protected static ?string $modelLabel = 'document';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $n = Document::query()->where('status', DocumentStatus::Pending->value)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'documentable']))
            ->defaultSort('id', 'asc')
            ->columns([
                ImageColumn::make('preview')
                    ->state(fn (Document $record) => $record->isImage() ? route('admin.document.file', $record) : null)
                    ->imageHeight(56)
                    ->url(fn (Document $record) => route('admin.document.file', $record), shouldOpenInNewTab: true),
                TextColumn::make('type.name')->label('Document')->weight('medium')->searchable(),
                TextColumn::make('owner')->label('Driver / vehicle')->state(fn (Document $record) => self::ownerLabel($record))
                    ->url(fn (Document $record) => self::ownerUrl($record)),
                TextColumn::make('number')->placeholder('—'),
                TextColumn::make('expires_at')->date()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => Ui::label($state)),
                TextColumn::make('created_at')->label('Uploaded')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(DocumentStatus::cases())->mapWithKeys(fn ($c) => [$c->value => Ui::label($c)]))
                    ->default(DocumentStatus::Pending->value),
                SelectFilter::make('document_type_id')->label('Type')->options(fn () => DocumentType::query()->orderBy('sort_order')->pluck('name', 'id')),
            ])
            ->recordActions([DocumentActions::open(), DocumentActions::approve(), DocumentActions::reject()])
            ->toolbarActions([
                BulkAction::make('approve')->label('Approve selected')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Documents that need an expiry date are skipped unless it was given at upload.')
                    ->action(function (Collection $records): void {
                        $ok = 0;
                        foreach ($records as $doc) {
                            try {
                                app(DocumentService::class)->review($doc->load('type'), true, null, auth()->user());
                                $ok++;
                            } catch (\Throwable) {
                            }
                        }
                        Notification::make()->title("Approved {$ok} of {$records->count()}")->success()->send();
                    }),
            ])
            ->emptyStateHeading('Nothing to review')
            ->emptyStateIcon(Heroicon::OutlinedCheckBadge);
    }

    public static function getPages(): array
    {
        return ['index' => ListDocuments::route('/')];
    }

    public static function ownerLabel(Document $record): string
    {
        $owner = $record->documentable;

        return match (true) {
            $owner instanceof DriverProfile => ($owner->user?->name ?? 'Driver').' · '.($owner->user?->phone ?? ''),
            $owner instanceof Vehicle => "{$owner->plate} · {$owner->make} {$owner->model} (".($owner->driver?->name ?? ($owner->fleet ? 'fleet: '.$owner->fleet->name : '?')).')',
            $owner instanceof \App\Models\Store => 'Store · '.$owner->name,
            default => '—',
        };
    }

    public static function ownerUrl(Document $record): ?string
    {
        $owner = $record->documentable;

        return match (true) {
            $owner instanceof DriverProfile => \App\Filament\Resources\Drivers\DriverResource::getUrl('view', ['record' => $owner]),
            $owner instanceof Vehicle => \App\Filament\Resources\Vehicles\VehicleResource::getUrl('view', ['record' => $owner]),
            $owner instanceof \App\Models\Store => \App\Filament\Resources\Stores\StoreResource::getUrl('view', ['record' => $owner]),
            default => null,
        };
    }
}
