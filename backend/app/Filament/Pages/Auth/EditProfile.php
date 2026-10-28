<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * "My profile" in the admin, store and fleet portals (the user menu, top right): name, email and a new password.
 * Changing the password or email asks for the current password. The mobile number is the login and stays fixed.
 */
class EditProfile extends BaseEditProfile
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label('Email')->email()->maxLength(255)->unique(ignoreRecord: true)
            ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtolower(trim((string) $state)) : null)
            ->helperText(fn () => \Filament\Facades\Filament::getCurrentPanel()?->getId() === 'admin'
                ? 'Optional.'
                : 'Optional. You can log in with it instead of your mobile number, and reset codes go here too.');
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->label('New password')->rule(Password::min(8))
            ->helperText('Leave empty to keep your current password. At least 8 characters.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn () => 'Mobile number (your login): '.($this->getUser()->phone ?? '—'))->color('gray'),
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
