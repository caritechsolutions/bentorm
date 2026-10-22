<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * POST /auth/otp/lookup — is this phone registered? New phones are asked for name + email
     * before a code is sent, so the code can go to both.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $phone = Phone::normalise($data['phone']);
        $user = User::query()->where('phone', $phone)->first();

        return response()->json([
            'phone' => $phone,
            'registered' => (bool) $user,
            'email_hint' => OtpService::maskEmail($user?->email),
        ]);
    }

    /**
     * POST /auth/otp/request — code by SMS, plus email: the account's email for registered phones,
     * or the `email` given by someone signing up.
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ]);
        $phone = Phone::normalise($data['phone']);
        $user = User::query()->where('phone', $phone)->first();

        $email = $user ? $user->email : (isset($data['email']) ? mb_strtolower(trim($data['email'])) : null);
        if (! $user && $email && User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['That email is already used by another account.']]);
        }

        $sent = $this->otp->request($phone, $email);

        return response()->json(array_filter([
            'message' => 'Verification code sent.',
            'phone' => $phone,
            'registered' => (bool) $user,
            'channels' => $sent['channels'],
            'email_hint' => in_array('email', $sent['channels'], true) ? OtpService::maskEmail($email) : null,
            'ttl_minutes' => (int) config('otp.ttl_minutes'),
            'code' => $sent['code'], // only present when OTP_EXPOSE_CODE=true
        ], fn ($v) => $v !== null));
    }

    /** POST /auth/otp/verify — logs in, creating the account on first use. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:10'],
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'role' => ['nullable', 'in:rider,driver'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $phone = Phone::normalise($data['phone']);

        // Not consumed yet: a first-time user who still has to give their name keeps the same code.
        $otp = $this->otp->verify($phone, $data['code'], consume: false);

        $user = User::query()->where('phone', $phone)->first();
        $isNew = false;

        if (! $user) {
            // New account: name and email are required. The email the code was sent to is used
            // unless a different one is given now.
            $email = isset($data['email']) ? mb_strtolower(trim($data['email'])) : $otp->email;
            if (empty($data['name']) || ! $email) {
                return response()->json([
                    'message' => 'Your name and email are needed to create an account.',
                    'needs_name' => empty($data['name']),
                    'needs_email' => ! $email,
                ], 422);
            }
            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => ['That email is already used by another account.']]);
            }
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $phone,
                'role' => $data['role'] ?? UserRole::Rider->value,
                'phone_verified_at' => now(),
            ]);
            if ($user->isDriver()) {
                $user->driverProfile()->create([]);
            }
            $isNew = true;
        } elseif (! $user->is_active) {
            abort(403, 'Your account has been deactivated.');
        } elseif (! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        $this->otp->consume($otp);
        $token = $user->createToken($data['device_name'] ?? 'app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'is_new' => $isNew,
            'user' => (new UserResource($user->load('driverProfile.currentVehicle')))->asSelf()->resolve($request),
        ], $isNew ? 201 : 200);
    }
}
