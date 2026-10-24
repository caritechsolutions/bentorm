<?php

namespace App\Filament\Store\Resources\Staff;

use App\Enums\UserRole;
use App\Filament\Store\Resources\Staff\Pages\ManageStaff;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Who can log in to this store (owners only). Adding someone by mobile number links their Ride Coop account,
 * or creates one; they set their password with "Forgot password" (a code to that phone).
 */
class StaffResource extends Resource
{
    protected static ?string $model = StoreMember::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?string $slug = 'staff';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        $store = Filament::getTenant();

        return $store instanceof Store && (Filament::auth()->user()?->isStoreOwner($store) ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->hiddenOn('edit'),
            TextInput::make('phone')->label('Mobile number')->tel()->required()->hiddenOn('edit'),
            TextInput::make('email')->email()->maxLength(255)->hiddenOn('edit')->helperText('Optional. Login codes go here too.'),
            Radio::make('role')->options(['staff' => 'Staff — catalogue and orders', 'owner' => 'Owner — also store settings and staff'])->default('staff')->required(),
        ]);
    }

    /** Find or create the person's account and attach them to the store. */
    public static function addMember(Store $store, array $data): StoreMember
    {
        try {
            $phone = Phone::normalise((string) $data['phone']);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['mountedActions.0.data.phone' => 'Enter a valid mobile number.']);
        }
        $user = User::query()->where('phone', $phone)->first();
        if ($user?->isAdmin()) {
            throw ValidationException::withMessages(['mountedActions.0.data.phone' => 'That number belongs to an admin account.']);
        }
        if ($user && $store->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['mountedActions.0.data.phone' => 'This person already has access to your store.']);
        }
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;
        if ($email && User::query()->where('email', $email)->where('id', '!=', $user?->id)->exists()) {
            throw ValidationException::withMessages(['mountedActions.0.data.email' => 'This email is already used by another account.']);
        }
        if (! $user) {
            $user = User::query()->create(['name' => trim((string) $data['name']), 'phone' => $phone, 'email' => $email, 'role' => UserRole::Rider]);
        } elseif ($email && blank($user->email)) {
            $user->forceFill(['email' => $email])->save();
        }

        return $store->storeMembers()->create(['user_id' => $user->id, 'role' => $data['role'] ?? 'staff']);
    }

    public static function table(Table $table): Table
    {
        $me = Filament::auth()->id();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Name')->weight('medium')->description(fn (StoreMember $r) => $r->user_id === $me ? 'You' : null),
                TextColumn::make('user.phone')->label('Mobile'),
                TextColumn::make('user.email')->label('Email')->placeholder('—'),
                TextColumn::make('role')->badge()->color(fn ($state) => $state === 'owner' ? 'warning' : 'gray'),
                TextColumn::make('user.password')->label('Can log in')->formatStateUsing(fn () => 'Yes')->placeholder('Not yet — use "Forgot password"'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (StoreMember $r) => $r->user_id !== $me),
                DeleteAction::make()->label('Remove')->visible(fn (StoreMember $r) => $r->user_id !== $me),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStaff::route('/')];
    }
}
