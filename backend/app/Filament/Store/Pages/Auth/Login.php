<?php

namespace App\Filament\Store\Pages\Auth;

use App\Support\Phone;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** Store portal login: phone number or email, plus password. */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Phone number or email')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim((string) $data['login']);
        if (str_contains($login, '@')) {
            return ['email' => mb_strtolower($login), 'password' => $data['password']];
        }
        try {
            $phone = Phone::normalise($login);
        } catch (ValidationException) {
            $phone = $login;
        }

        return ['phone' => $phone, 'password' => $data['password']];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
