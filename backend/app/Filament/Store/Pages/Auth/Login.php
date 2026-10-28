<?php

namespace App\Filament\Store\Pages\Auth;

use App\Models\User;
use App\Support\Phone;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** Store and fleet portal login: phone number or email, plus password. */
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
            'data.login' => $this->isAdminWithRightPassword()
                ? 'This is an admin account. Sign in at the admin panel ('.(config('bentorm.admin_host') ?: 'newadmin').') instead.'
                : __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }

    /** Only says "admin account" to someone who knows its password, so it never reveals who the admins are. */
    private function isAdminWithRightPassword(): bool
    {
        $creds = $this->getCredentialsFromFormData(['login' => $this->data['login'] ?? '', 'password' => $this->data['password'] ?? '']);
        $user = User::query()->where(isset($creds['email']) ? 'email' : 'phone', $creds['email'] ?? $creds['phone'])->first();

        return $user?->isAdmin() && $user->password && Hash::check((string) $creds['password'], $user->password);
    }
}
