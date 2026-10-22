<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\Ui;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Riders & payments';

    protected static ?string $navigationLabel = 'Users';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false; // accounts are created by phone sign-up
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(100),
                TextInput::make('phone')->disabled()->dehydrated(false),
                TextInput::make('email')->email()->maxLength(255)->unique(ignoreRecord: true),
                Select::make('role')->options(collect(UserRole::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)]))->required()
                    ->helperText('Making someone a driver creates an empty driver application for them.'),
                Toggle::make('is_active')->label('Active')->helperText('Deactivating signs the user out of all apps.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('phone')->searchable()->copyable(),
                TextColumn::make('email')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('role')->badge()->color(fn ($state) => $state === UserRole::Admin ? 'info' : 'gray')->formatStateUsing(fn ($state) => ucfirst($state->value)),
                ToggleColumn::make('is_active')->label('Active')
                    ->disabled(fn (User $record) => $record->id === auth()->id())
                    ->afterStateUpdated(fn (User $record, $state) => $state ? null : $record->tokens()->delete()),
                TextColumn::make('ridesAsRider_count')->counts('ridesAsRider')->label('Rides')->sortable(),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable()->placeholder('Never'),
                TextColumn::make('created_at')->label('Joined')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('role')->options(collect(UserRole::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)]))])
            ->recordActions([
                EditAction::make(),
                Action::make('password')->label('Panel password')->icon(Heroicon::OutlinedKey)->color('gray')
                    ->visible(fn (User $record) => $record->isAdmin())
                    ->modalDescription('Sets a new password for this admin to sign in to this panel.')
                    ->schema([TextInput::make('password')->password()->revealable()->required()->minLength(10)
                        ->default(fn () => Str::password(16, symbols: false))])
                    ->action(function (User $record, array $data): void {
                        $record->forceFill(['password' => $data['password']])->save();
                        Notification::make()->title('Password updated')->body('Share it with the admin securely.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/'), 'edit' => EditUser::route('/{record}/edit')];
    }
}
