<?php

namespace App\Filament\Store\Pages\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\OtpService;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Store owner sign-up. Step 1: name, phone, email, password → a code goes to the phone (SMS) and email.
 * Step 2: the code proves the phone; then the account is created — or, if this phone already rides with
 * Ride Coop, that account is used (same person, one login). Next stop: "Register your store" (or fleet: the fleet
 * portal uses this page too).
 */
class Register extends BaseRegister
{
    public bool $codeSent = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Create a login for your '.self::what().'. We\'ll text a code to your phone to confirm it\'s you.')->color('gray'),
            $this->getNameFormComponent()->label('Your name')->disabled(fn () => $this->codeSent),
            TextInput::make('phone')->label('Mobile number')->tel()->required()->placeholder('1 246 …')->disabled(fn () => $this->codeSent),
            TextInput::make('email')->label('Email')->email()->required()->maxLength(255)->disabled(fn () => $this->codeSent),
            TextInput::make('password')->label('Password')->password()->revealable()->required()->rule(Password::min(8))
                ->same('passwordConfirmation')->disabled(fn () => $this->codeSent),
            TextInput::make('passwordConfirmation')->label('Confirm password')->password()->revealable()->required()->dehydrated(false)
                ->disabled(fn () => $this->codeSent),
            TextInput::make('code')->label('Code from the text message or email')->numeric()->autocomplete('one-time-code')
                ->visible(fn () => $this->codeSent)->required(fn () => $this->codeSent),
        ]);
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()->label(fn () => $this->codeSent ? 'Confirm and continue' : 'Send me a code');
    }

    public function register(): ?RegistrationResponse
    {
        $otp = app(OtpService::class);
        $data = $this->data;

        try {
            $phone = Phone::normalise((string) ($data['phone'] ?? ''));
        } catch (ValidationException) {
            throw ValidationException::withMessages(['data.phone' => 'Enter a valid mobile number.']);
        }

        if (! $this->codeSent) {
            $this->form->validate();
            $this->checkConflicts($phone, (string) $data['email']);
            try {
                $otp->request($phone, (string) $data['email']);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['data.phone' => collect($e->errors())->flatten()->first()]);
            }
            $this->codeSent = true;
            Notification::make()->title('Code sent to your phone and email')->success()->send();

            return null;
        }

        $this->form->validate();
        try {
            $code = $otp->verify($phone, (string) ($data['code'] ?? ''), consume: false);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.code' => collect($e->errors())->flatten()->first()]);
        }
        $this->checkConflicts($phone, (string) $data['email']);
        $otp->consume($code);

        $user = User::query()->where('phone', $phone)->first() ?? new User(['phone' => $phone, 'role' => UserRole::Rider]);
        $user->forceFill([
            'name' => $user->exists && filled($user->name) ? $user->name : trim((string) $data['name']),
            'email' => mb_strtolower(trim((string) $data['email'])),
            'password' => Hash::make((string) $data['password']),
            'phone_verified_at' => now(),
        ])->save();

        Filament::auth()->login($user);
        session()->regenerate();

        return app(RegistrationResponse::class);
    }

    /** Shared by the store portal and the fleet portal. */
    private static function what(): string
    {
        return Filament::getCurrentPanel()?->getId() === 'fleet' ? 'fleet' : 'store';
    }

    /** The email can't belong to someone else; an admin's phone can't be taken over from here. */
    private function checkConflicts(string $phone, string $email): void
    {
        $existing = User::query()->where('phone', $phone)->first();
        if ($existing?->isAdmin()) {
            throw ValidationException::withMessages(['data.phone' => 'This number belongs to an admin account. Use a different number for your '.self::what().'.']);
        }
        $emailOwner = User::query()->where('email', mb_strtolower(trim($email)))->first();
        if ($emailOwner && $emailOwner->id !== $existing?->id) {
            throw ValidationException::withMessages(['data.email' => 'This email is already used by another account.']);
        }
        if ($existing && ! $existing->is_active) {
            throw ValidationException::withMessages(['data.phone' => 'This account is disabled. Contact Ride Coop.']);
        }
    }
}
