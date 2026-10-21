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
            'avatar_url' => ['nullable', 'url', 'max:500'],
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
        DeviceToken::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'token' => $data['token']],
            ['platform' => $data['platform']],
        );

        return response()->json(['message' => 'Device registered.']);
    }
}
