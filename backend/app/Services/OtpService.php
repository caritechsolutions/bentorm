<?php

namespace App\Services;

use App\Contracts\SmsSender;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Generate and send a code. Returns the code only when config('otp.expose_code') is on.
     */
    public function request(string $phone): ?string
    {
        $cooldown = (int) config('otp.resend_cooldown_seconds');
        $recent = OtpCode::query()->where('phone', $phone)->latest('id')->first();
        if ($recent && $recent->created_at->gt(now()->subSeconds($cooldown))) {
            throw ValidationException::withMessages([
                'phone' => ["Please wait {$cooldown} seconds before requesting another code."],
            ]);
        }

        $code = config('otp.static_code') ?: $this->generate();

        OtpCode::query()->where('phone', $phone)->whereNull('consumed_at')->delete();
        OtpCode::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('otp.ttl_minutes')),
        ]);

        $this->sms->send($phone, "Your Bentorm verification code is {$code}. It expires in ".config('otp.ttl_minutes').' minutes.');

        return config('otp.expose_code') ? $code : null;
    }

    /** Verify a code; throws a validation error on failure. */
    public function verify(string $phone, string $code): void
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

        $otp->forceFill(['consumed_at' => now()])->save();
    }

    private function generate(): string
    {
        $length = (int) config('otp.length', 6);

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
