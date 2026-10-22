<?php

namespace App\Filament\Pages\Auth;

use App\Support\Phone;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** Admin login with phone number + password (the apps use phone + OTP). */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('Phone number')
            ->tel()
            ->placeholder('1 246 253 0487')
            ->required()
            ->autocomplete('tel')
            ->autofocus();
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        try {
            $phone = Phone::normalise((string) $data['phone']);
        } catch (ValidationException) {
            $phone = (string) $data['phone'];
        }

        return ['phone' => $phone, 'password' => $data['password']];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.phone' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
