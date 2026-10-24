<?php

namespace App\Filament\Store\Pages\Auth;

use App\Models\User;
use App\Services\OtpService;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Forgot password: a code to the account's phone (and email), then a new password. Not for admin accounts. */
class ResetPassword extends RequestPasswordReset
{
    public bool $codeSent = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('We\'ll send a code to the phone (and email) on your account.')->color('gray'),
            TextInput::make('phone')->label('Mobile number')->tel()->required()->disabled(fn () => $this->codeSent),
            TextInput::make('code')->label('Code')->numeric()->autocomplete('one-time-code')->visible(fn () => $this->codeSent)->required(fn () => $this->codeSent),
            TextInput::make('password')->label('New password')->password()->revealable()->rule(Password::min(8))->same('passwordConfirmation')
                ->visible(fn () => $this->codeSent)->required(fn () => $this->codeSent),
            TextInput::make('passwordConfirmation')->label('Confirm new password')->password()->revealable()->dehydrated(false)
                ->visible(fn () => $this->codeSent)->required(fn () => $this->codeSent),
        ]);
    }

    public function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()->label(fn () => $this->codeSent ? 'Set new password' : 'Send me a code');
    }

    public function request(): void
    {
        $otp = app(OtpService::class);
        try {
            $phone = Phone::normalise((string) ($this->data['phone'] ?? ''));
        } catch (ValidationException) {
            throw ValidationException::withMessages(['data.phone' => 'Enter a valid mobile number.']);
        }
        $user = User::query()->where('phone', $phone)->first();
        $usable = $user && $user->is_active && ! $user->isAdmin();

        if (! $this->codeSent) {
            if ($usable) {
                try {
                    $otp->request($phone, $user->email);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['data.phone' => collect($e->errors())->flatten()->first()]);
                }
            }
            // Same answer either way, so the form doesn't reveal which numbers have accounts.
            $this->codeSent = true;
            Notification::make()->title('If that number has an account, a code is on its way.')->success()->send();

            return;
        }

        $this->form->validate();
        if (! $usable) {
            throw ValidationException::withMessages(['data.code' => 'That code is not right.']);
        }
        try {
            $otp->consume($otp->verify($phone, (string) $this->data['code'], consume: false));
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.code' => collect($e->errors())->flatten()->first()]);
        }
        $user->forceFill(['password' => Hash::make((string) $this->data['password'])])->save();
        Notification::make()->title('Password changed. You can log in now.')->success()->send();
        $this->redirect(Filament::getLoginUrl());
    }
}
