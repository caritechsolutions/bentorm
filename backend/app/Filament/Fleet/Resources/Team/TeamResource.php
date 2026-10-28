<?php

namespace App\Filament\Fleet\Resources\Team;

use App\Enums\UserRole;
use App\Filament\Fleet\Resources\Team\Pages\ManageTeam;
use App\Models\Fleet;
use App\Models\FleetMember;
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
 * Who can log in to this fleet (owners only). Managers run cars, drivers and trips; owners also see bank details,
 * settings and the team. New people set their password with "Forgot password" (a code to their phone).
 */
class TeamResource extends Resource
{
    protected static ?string $model = FleetMember::class;

    protected static ?string $tenantRelationshipName = 'members';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Team';

    protected static ?string $modelLabel = 'team member';

    protected static ?string $pluralModelLabel = 'team';

    protected static ?string $slug = 'team';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        $fleet = Filament::getTenant();

        return $fleet instanceof Fleet && (Filament::auth()->user()?->isFleetOwner($fleet) ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->hiddenOn('edit'),
            TextInput::make('phone')->label('Mobile number')->tel()->required()->hiddenOn('edit'),
            TextInput::make('email')->email()->maxLength(255)->hiddenOn('edit')->helperText('Optional. Login codes go here too.'),
            Radio::make('role')->options(['manager' => 'Manager — cars, drivers and trips', 'owner' => 'Owner — also statement, bank details, settings and team'])
                ->default('manager')->required(),
        ]);
    }

    /** Find or create the person's account and add them to the fleet. */
    public static function addMember(Fleet $fleet, array $data): FleetMember
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
        if ($user && $fleet->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['mountedActions.0.data.phone' => 'This person already has access to your fleet.']);
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

        return $fleet->members()->create(['user_id' => $user->id, 'role' => $data['role'] ?? 'manager']);
    }

    public static function table(Table $table): Table
    {
        $me = Filament::auth()->id();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Name')->weight('medium')->description(fn (FleetMember $r) => $r->user_id === $me ? 'You' : null),
                TextColumn::make('user.phone')->label('Mobile'),
                TextColumn::make('user.email')->label('Email')->placeholder('—'),
                TextColumn::make('role')->badge()->color(fn ($state) => $state === 'owner' ? 'warning' : 'gray'),
                TextColumn::make('user.password')->label('Can log in')->formatStateUsing(fn () => 'Yes')->placeholder('Not yet — use "Forgot password"'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (FleetMember $r) => $r->user_id !== $me),
                DeleteAction::make()->label('Remove')->visible(fn (FleetMember $r) => $r->user_id !== $me),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTeam::route('/')];
    }
}
