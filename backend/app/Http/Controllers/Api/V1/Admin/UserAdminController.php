<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserAdminController extends Controller
{
    /** GET /admin/users?role=rider&q=... */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = User::query();
        if ($role = $request->query('role')) {
            $q->where('role', $role);
        }
        if ($search = $request->query('q')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        return UserResource::collection($q->latest('id')->paginate(25));
    }

    /** PATCH /admin/users/{user}  {is_active?, role?} */
    public function update(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'in:rider,driver,admin'],
            'name' => ['sometimes', 'string', 'max:100'],
        ]);
        if ($user->id === $request->user()->id && isset($data['is_active']) && ! $data['is_active']) {
            abort(422, 'You cannot deactivate yourself.');
        }
        $user->update($data);
        if (isset($data['is_active']) && ! $data['is_active']) {
            $user->tokens()->delete();
        }
        if (($data['role'] ?? null) === 'driver') {
            $user->driverProfile()->firstOrCreate([]);
        }

        return new UserResource($user->fresh()->load('driverProfile'));
    }
}
