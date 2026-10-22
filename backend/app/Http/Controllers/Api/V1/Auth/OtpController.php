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

class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /** POST /auth/otp/request */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $phone = Phone::normalise($data['phone']);

        $code = $this->otp->request($phone);

        return response()->json(array_filter([
            'message' => 'Verification code sent.',
            'phone' => $phone,
            'ttl_minutes' => (int) config('otp.ttl_minutes'),
            'code' => $code, // only present when OTP_EXPOSE_CODE=true
        ], fn ($v) => $v !== null));
    }

    /** POST /auth/otp/verify — logs in, creating the account on first use. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:10'],
            'name' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:rider,driver'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $phone = Phone::normalise($data['phone']);

        // Not consumed yet: a first-time user who still has to give their name keeps the same code.
        $otp = $this->otp->verify($phone, $data['code'], consume: false);

        $user = User::query()->where('phone', $phone)->first();
        $isNew = false;

        if (! $user) {
            if (empty($data['name'])) {
                return response()->json(['message' => 'Name is required to create an account.', 'needs_name' => true], 422);
            }
            $user = User::query()->create([
                'name' => $data['name'],
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
