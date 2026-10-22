<?php

namespace App\Services;

use App\Contracts\SmsSender;
use App\Jobs\SendOtpEmailJob;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Generate a code and send it by SMS and, when there is one, to the email address too.
     * $email: the account's email (existing users) or the one a new user is signing up with.
     *
     * @return array{code: ?string, channels: list<string>} code only when config('otp.expose_code') is on
     */
    public function request(string $phone, ?string $email = null): array
    {
        $cooldown = (int) config('otp.resend_cooldown_seconds');
        $recent = OtpCode::query()->where('phone', $phone)->latest('id')->first();
        if ($recent && $recent->created_at->gt(now()->subSeconds($cooldown))) {
            throw ValidationException::withMessages([
                'phone' => ["Please wait {$cooldown} seconds before requesting another code."],
            ]);
        }

        $code = config('otp.static_code') ?: $this->generate();
        $minutes = (int) config('otp.ttl_minutes');

        OtpCode::query()->where('phone', $phone)->whereNull('consumed_at')->delete();
        $otp = OtpCode::query()->create([
            'phone' => $phone,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $channels = [];
        $brand = config('bentorm.brand_name', 'Ride Coop');
        try {
            $this->sms->send($phone, "Your {$brand} code is {$code}. It expires in {$minutes} minutes.");
            $channels[] = 'sms';
        } catch (\Throwable $e) {
            Log::error('OTP SMS failed: '.$e->getMessage(), ['phone' => $phone]);
        }

        if ($email) {
            SendOtpEmailJob::dispatch($email, $code, $minutes);
            $channels[] = 'email';
        }

        if (! $channels) {
            // Don't leave a code nobody received (it would also block a retry for the cooldown).
            $otp->delete();
            throw new ServiceUnavailableHttpException(null, 'We couldn\'t send the text message right now. Please try again in a minute.');
        }

        return ['code' => config('otp.expose_code') ? $code : null, 'channels' => $channels];
    }

    /** "r•••@gmail.com" — enough for the rider to recognise their address. */
    public static function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    /** Verify a code; throws a validation error on failure. */
    /**
     * Check a code. With $consume = false the code stays usable (e.g. while a first-time user is
     * asked for their name) — call consume() once the login actually happens.
     */
    public function verify(string $phone, string $code, bool $consume = true): OtpCode
    {
        $otp = OtpCode::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || $otp->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => ['Code expired. Please request a new one.']]);
        }

        if ($otp->attempts >= (int) config('otp.max_attempts')) {
            throw ValidationException::withMessages(['code' => ['Too many attempts. Please request a new code.']]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            throw ValidationException::withMessages(['code' => ['Incorrect code.']]);
        }

        if ($consume) {
            $this->consume($otp);
        }

        return $otp;
    }

    public function consume(OtpCode $otp): void
    {
        $otp->forceFill(['consumed_at' => now()])->save();
    }

    private function generate(): string
    {
        $length = (int) config('otp.length', 6);

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
