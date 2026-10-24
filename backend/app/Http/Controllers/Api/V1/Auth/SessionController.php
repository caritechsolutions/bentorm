<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /** GET /me */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('driverProfile.currentVehicle'));
    }

    /** PATCH /me */
    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);
        $request->user()->update($data);

        return new UserResource($request->user()->fresh()->load('driverProfile.currentVehicle'));
    }

    /** POST /auth/logout — revokes the current token only. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** POST /devices — register a push token. */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:android,ios,web'],
        ]);
        // A phone belongs to whoever is signed in on it now: drop the token from any other account.
        DeviceToken::query()->where('token', $data['token'])->where('user_id', '!=', $request->user()->id)->delete();
        DeviceToken::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'token' => $data['token']],
            ['platform' => $data['platform'], 'last_used_at' => now()],
        );

        return response()->json(['message' => 'Device registered.']);
    }

    /** DELETE /devices {token} — stop pushes to this phone (signing out). */
    public function forgetDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        DeviceToken::query()->where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
