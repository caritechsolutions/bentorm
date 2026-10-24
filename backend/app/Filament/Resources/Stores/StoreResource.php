<?php

namespace App\Filament\Resources\Stores;

use App\Enums\StoreStatus;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\Pages\ListStores;
use App\Filament\Resources\Stores\Pages\ViewStore;
use App\Filament\Support\ApprovalActions;
use App\Models\ShopCategory;
use App\Models\Store;
use App\Services\ComplianceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Shops on the marketplace: review, approve, suspend; per-store commission. */
class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $n = Store::query()->where('status', StoreStatus::Pending->value)->count();

        return $n ? (string) $n : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Store')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120),
                Select::make('shop_category_id')->label('Category')->required()->options(fn () => ShopCategory::query()->orderBy('sort_order')->pluck('name', 'id')),
                TextInput::make('phone')->tel()->maxLength(32),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('address')->maxLength(255)->columnSpanFull(),
            ]),
            Section::make('Commission')->schema([
                TextInput::make('commission_rate')->label('Commission on items for this store')->numeric()->minValue(0)->maxValue(100)->suffix('%')
                    ->placeholder('Default from Shop settings')
                    ->formatStateUsing(fn ($state) => $state === null ? null : round($state * 100, 2))
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? round(((float) $state) / 100, 4) : null)
                    ->helperText('Leave empty to use the default commission.'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Store')->columns(3)->columnSpanFull()->schema([
                ImageEntry::make('logo_path')->label('Logo')->disk('public')->imageHeight(64)->placeholder('No logo'),
                TextEntry::make('name')->weight('bold'),
                TextEntry::make('category.name')->label('Category'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (StoreStatus $state) => $state->label())
                    ->color(fn (StoreStatus $state) => match ($state) { StoreStatus::Approved => 'success', StoreStatus::Pending => 'warning', default => 'danger' }),
                TextEntry::make('review_note')->label('Note')->placeholder('—'),
                TextEntry::make('is_open')->label('Open now')->formatStateUsing(fn (Store $record) => $record->isAcceptingOrders() ? 'Yes' : 'No'),
                TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                TextEntry::make('address')->placeholder('—'),
                TextEntry::make('lat')->label('Map pin')->formatStateUsing(fn (Store $record) => $record->lat ? sprintf('%.5f, %.5f', $record->lat, $record->lng) : '—')
                    ->url(fn (Store $record) => $record->lat ? "https://www.google.com/maps?q={$record->lat},{$record->lng}" : null, shouldOpenInNewTab: true),
                TextEntry::make('serviceArea.name')->label('Service area')->placeholder('Outside every area'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('owners')->label('Owner(s)')->state(fn (Store $record) => $record->owners->map(fn ($u) => "{$u->name} · {$u->phone}")->implode(', ') ?: '—'),
            ]),
            Section::make('Operations')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('prep_minutes')->label('Prep time')->suffix(' min'),
                TextEntry::make('delivery_radius_km')->label('Delivers up to')->suffix(' km'),
                TextEntry::make('min_order')->label('Minimum order')->numeric(2),
                TextEntry::make('products_count')->label('Products')->state(fn (Store $record) => $record->products()->count().' ('.$record->products()->where('is_available', true)->count().' available)'),
                TextEntry::make('vat_registered')->label('VAT')->formatStateUsing(fn (Store $record) => $record->vat_registered ? ($record->prices_include_vat ? 'Registered, prices include VAT' : 'Registered, prices exclude VAT') : 'Not registered'),
                TextEntry::make('commission_rate')->label('Commission')->formatStateUsing(fn ($state) => $state === null ? 'Default' : round($state * 100, 2).'%')->placeholder('Default'),
                TextEntry::make('issues')->label('Missing for approval')->columnSpan(2)
                    ->state(fn (Store $record) => implode(' · ', app(ComplianceService::class)->storeIssues($record)) ?: 'Nothing — ready to approve'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'serviceArea'])->withCount('products'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('logo_path')->label('')->disk('public')->imageHeight(36)->circular(),
                TextColumn::make('name')->searchable()->weight('medium')->description(fn (Store $r) => $r->address),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (StoreStatus $state) => $state->label())
                    ->color(fn (StoreStatus $state) => match ($state) { StoreStatus::Approved => 'success', StoreStatus::Pending => 'warning', default => 'danger' }),
                IconColumn::make('is_open')->label('Open')->boolean(),
                TextColumn::make('products_count')->label('Products'),
                TextColumn::make('created_at')->label('Signed up')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(StoreStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                SelectFilter::make('shop_category_id')->label('Category')->options(fn () => ShopCategory::query()->pluck('name', 'id')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return Action[] */
    public static function approvalActions(): array
    {
        $compliance = app(ComplianceService::class);

        return [
            Action::make('approve')->label('Approve store')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (Store $record) => $record->status !== StoreStatus::Approved)
                ->modalDescription(fn (Store $record) => ApprovalActions::issuesHtml($compliance->storeIssues($record)))
                ->schema(fn (Store $record) => $compliance->storeIssues($record)
                    ? [Toggle::make('force')->label('Approve anyway')->required()->accepted()]
                    : [])
                ->action(function (Store $record): void {
                    $record->forceFill(['status' => StoreStatus::Approved, 'review_note' => null, 'approved_at' => now(), 'approved_by' => auth()->id()])->save();
                    Notification::make()->title("{$record->name} approved")->success()->send();
                }),
            self::statusAction('suspend', 'Suspend', 'warning', Heroicon::OutlinedPauseCircle, StoreStatus::Suspended),
            self::statusAction('reject', 'Reject', 'danger', Heroicon::OutlinedNoSymbol, StoreStatus::Rejected),
        ];
    }

    private static function statusAction(string $name, string $label, string $color, $icon, StoreStatus $to): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(fn (Store $record) => $record->status !== $to && ($to !== StoreStatus::Suspended || $record->status === StoreStatus::Approved))
            ->schema([Textarea::make('note')->label('Reason (shown to the store)')->required()->maxLength(255)])
            ->action(function (Store $record, array $data) use ($to): void {
                $record->forceFill(['status' => $to, 'review_note' => $data['note'], 'is_open' => false])->save();
                Notification::make()->title("{$record->name}: ".strtolower($to->label()))->warning()->send();
            });
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStores::route('/'),
            'view' => ViewStore::route('/{record}'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // stores sign up themselves in the store portal
    }
}
