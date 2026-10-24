<?php

namespace App\Filament\Store\Pages;

use App\Enums\StoreStatus;
use App\Models\DocumentType;
use App\Models\Store;
use App\Services\ComplianceService;
use App\Services\DocumentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;

/** The store's home: approval status and what's missing, open/busy switches, documents. */
class Overview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $slug = 'overview';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Overview';

    private function store(): Store
    {
        /** @var Store $s */
        $s = Filament::getTenant();

        return $s->refresh();
    }

    /** Rebuild the page after an action changed what it shows (schemas are cached per request). */
    private function refreshContent(): void
    {
        unset($this->cachedSchemas['content']);
    }

    public function getSubheading(): ?string
    {
        return $this->store()->name;
    }

    public function content(Schema $schema): Schema
    {
        $store = $this->store();
        $compliance = app(ComplianceService::class);
        $issues = $compliance->storeIssues($store);
        $checklist = $compliance->checklist($store);

        $statusText = match ($store->status) {
            StoreStatus::Pending => $issues
                ? 'Waiting for approval. Finish the steps below so we can review your store.'
                : 'Waiting for approval. Everything is in — Ride Coop will review your store shortly.',
            StoreStatus::Approved => $store->isAcceptingOrders()
                ? 'Approved and OPEN — customers can see your store and order.'
                : ($store->is_open ? 'Approved and switched open, but outside your opening hours right now.' : 'Approved. Switch the store open when you are ready to take orders.'),
            StoreStatus::Rejected => 'Not approved'.($store->review_note ? ': '.$store->review_note : '.').' Fix the issue and contact Ride Coop.',
            StoreStatus::Suspended => 'Suspended'.($store->review_note ? ': '.$store->review_note : '.').' Customers can\'t see your store.',
        };

        $docs = [];
        foreach ($checklist as $row) {
            /** @var DocumentType $type */
            $type = $row['type'];
            $state = $row['state'];
            $note = $row['document']?->review_note;
            $docs[] = Grid::make(['default' => 1, 'md' => 3])->schema([
                Text::make($type->name.($type->is_required ? '' : ' (optional)'))->weight('bold'),
                Text::make(match ($state) {
                    'missing' => $type->is_required ? 'Needed' : 'Not uploaded',
                    'pending' => 'Uploaded — being reviewed',
                    'approved' => 'Approved',
                    'expiring_soon' => 'Approved — expires soon',
                    'expired' => 'Expired — upload a new one',
                    'rejected' => 'Not accepted'.($note ? ': '.$note : ''),
                    default => $state,
                })->color(match ($state) { 'approved' => 'success', 'pending', 'expiring_soon' => 'warning', 'missing' => $type->is_required ? 'danger' : 'gray', default => 'danger' }),
                Actions::make([
                    $this->uploadAction()->arguments(['type' => $type->id])->label($state === 'missing' ? 'Upload' : 'Replace'),
                ])->alignEnd(),
            ]);
        }

        return $schema->components([
            Section::make('Status')->schema([
                Text::make($statusText)->size('lg')->weight('medium')->color(match ($store->status) {
                    StoreStatus::Approved => 'success', StoreStatus::Pending => 'warning', default => 'danger',
                }),
                ...array_map(fn (string $i) => Text::make('• '.$i), $store->status === StoreStatus::Approved ? [] : $issues),
                Actions::make([$this->toggleOpenAction(), $this->toggleBusyAction()])->visible($store->isApproved()),
            ]),
            Section::make('Documents')->description('Photos or PDFs. Ride Coop checks these before approving your store.')
                ->schema($docs ?: [Text::make('No documents needed.')]),
        ]);
    }

    public function toggleOpenAction(): Action
    {
        $open = $this->store()->is_open;

        return Action::make('toggleOpen')
            ->label($open ? 'Close the store' : 'Open the store')
            ->color($open ? 'gray' : 'success')
            ->icon($open ? Heroicon::OutlinedPauseCircle : Heroicon::OutlinedPlayCircle)
            ->visible(fn () => $this->store()->isApproved())
            ->requiresConfirmation($open)
            ->modalDescription('Customers will not be able to order until you open again.')
            ->action(function (): void {
                $s = $this->store();
                abort_unless($s->isApproved(), 403);
                $s->forceFill(['is_open' => ! $s->is_open])->save();
                Notification::make()->title($s->is_open ? 'Your store is open' : 'Your store is closed')->success()->send();
                $this->refreshContent();
            });
    }

    public function toggleBusyAction(): Action
    {
        $s = $this->store();

        return Action::make('toggleBusy')
            ->label($s->is_busy ? 'Not busy any more' : 'We\'re busy (+'.$s->busy_extra_minutes.' min)')
            ->color($s->is_busy ? 'warning' : 'gray')
            ->icon(Heroicon::OutlinedClock)
            ->visible(fn () => $this->store()->isApproved())
            ->action(function (): void {
                $s = $this->store();
                $s->forceFill(['is_busy' => ! $s->is_busy])->save();
                $this->refreshContent();
            });
    }

    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(fn (array $arguments) => DocumentType::query()->find($arguments['type'] ?? 0)?->name ?? 'Upload')
            ->modalDescription(fn (array $arguments) => DocumentType::query()->find($arguments['type'] ?? 0)?->description)
            ->schema(function (array $arguments): array {
                $type = DocumentType::query()->findOrFail($arguments['type'] ?? 0);

                return array_filter([
                    FileUpload::make('file')->label('Photo or PDF')->required()->storeFiles(false)->maxSize(10240)
                        ->acceptedFileTypes($type->is_image_only ? ['image/*'] : ['image/*', 'application/pdf']),
                    $type->requires_number ? TextInput::make('number')->label('Document number')->required()->maxLength(64) : null,
                    $type->requires_expiry ? DatePicker::make('expires_at')->label('Expiry date')->required()->minDate(now()) : null,
                ]);
            })
            ->action(function (array $data, array $arguments): void {
                $store = $this->store();
                $type = DocumentType::query()->where('applies_to', 'store')->findOrFail($arguments['type'] ?? 0);
                $file = $data['file'];
                abort_unless($file instanceof UploadedFile, 422);
                app(DocumentService::class)->upload($store, $type, $file, $data['number'] ?? null, $data['expires_at'] ?? null, Filament::auth()->user());
                Notification::make()->title('Uploaded — we\'ll review it shortly')->success()->send();
                $this->refreshContent();
            });
    }
}
