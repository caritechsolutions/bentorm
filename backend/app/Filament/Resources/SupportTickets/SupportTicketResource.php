<?php

namespace App\Filament\Resources\SupportTickets;

use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Resources\Trips\TripResource;
use App\Models\SupportTicket;
use App\Services\SupportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Messages from riders and drivers (incl. lost items). Reply here; they get a push and read it in the app. */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Tickets';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $n = SupportTicket::query()->where('status', 'open')->count();

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

    public static function statusColor(string $s): string
    {
        return ['open' => 'warning', 'answered' => 'info', 'closed' => 'gray'][$s] ?? 'gray';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('id')->label('#')->formatStateUsing(fn (SupportTicket $r) => $r->number()),
                TextColumn::make('status')->badge()->color(fn ($state) => self::statusColor($state))
                    ->formatStateUsing(fn ($state) => ['open' => 'Needs reply', 'answered' => 'Replied', 'closed' => 'Closed'][$state] ?? $state),
                TextColumn::make('subject')->searchable()->wrap()->description(fn (SupportTicket $r) => $r->categoryLabel()),
                TextColumn::make('user.name')->label('From')->description(fn (SupportTicket $r) => ($r->user?->isDriver() ? 'Driver · ' : '').$r->user?->phone)->searchable(),
                TextColumn::make('last_message_at')->label('Last message')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Needs reply', 'answered' => 'Replied', 'closed' => 'Closed'])->default('open'),
                SelectFilter::make('category')->options(SupportTicket::CATEGORIES),
            ])
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (SupportTicket $record) => $record->number().' · '.$record->subject)->columns(3)->columnSpanFull()->schema([
                TextEntry::make('status')->badge()->color(fn ($state) => self::statusColor($state)),
                TextEntry::make('user.name')->label('From')->formatStateUsing(fn (SupportTicket $record) => "{$record->user?->name} · {$record->user?->phone}"),
                TextEntry::make('category')->formatStateUsing(fn (SupportTicket $record) => $record->categoryLabel()),
                TextEntry::make('trip.public_id')->label('Trip')->placeholder('—')
                    ->url(fn (SupportTicket $record) => $record->trip ? TripResource::getUrl('view', ['record' => $record->trip->public_id]) : null),
                TextEntry::make('trip.driver.name')->label('Driver on that trip')->placeholder('—')
                    ->formatStateUsing(fn (SupportTicket $record) => $record->trip?->driver ? "{$record->trip->driver->name} · {$record->trip->driver->phone}" : null),
                TextEntry::make('order_id')->label('Order')->placeholder('—')->formatStateUsing(fn (SupportTicket $record) => $record->order?->number()),
            ]),
            Section::make('Conversation')->columnSpanFull()->schema([
                RepeatableEntry::make('messages')->hiddenLabel()->schema([
                    TextEntry::make('body')->hiddenLabel()
                        ->formatStateUsing(fn ($record) => ($record->is_staff ? 'Support ('.($record->user?->firstName() ?? 'staff').')' : ($record->user?->firstName() ?? 'Customer')).
                            ' · '.$record->created_at?->diffForHumans()."\n\n".$record->body)
                        ->extraAttributes(['style' => 'white-space:pre-line']),
                ]),
            ]),
        ]);
    }

    /** @return Action[] */
    public static function actions(): array
    {
        return [
            Action::make('reply')->label('Reply')->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->schema([Textarea::make('body')->label('Reply (the customer gets a push and reads it in the app)')->rows(5)->required()])
                ->action(function (SupportTicket $record, array $data) {
                    app(SupportService::class)->reply($record, auth()->user(), $data['body'], true);
                    Notification::make()->title('Reply sent')->success()->send();
                }),
            Action::make('close')->label('Close')->icon(Heroicon::OutlinedCheckCircle)->color('gray')
                ->visible(fn (SupportTicket $record) => $record->status !== 'closed')
                ->action(fn (SupportTicket $record) => $record->update(['status' => 'closed'])),
            Action::make('reopen')->label('Reopen')->color('gray')
                ->visible(fn (SupportTicket $record) => $record->status === 'closed')
                ->action(fn (SupportTicket $record) => $record->update(['status' => 'open'])),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListSupportTickets::route('/'), 'view' => ViewSupportTicket::route('/{record}')];
    }
}
