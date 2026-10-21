<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Resources\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverLocationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DriverAdminController extends Controller
{
    /** GET /admin/drivers?status=pending&q=... */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = User::query()->where('role', 'driver')->with(['driverProfile.currentVehicle']);

        if ($status = $request->query('status')) {
            $q->whereHas('driverProfile', fn ($p) => $p->where('status', $status));
        }
        if ($search = $request->query('q')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        return UserResource::collection($q->latest('id')->paginate(25));
    }

    /** GET /admin/drivers/{user} */
    public function show(User $user): UserResource
    {
        abort_unless($user->isDriver(), 404);

        return new UserResource($user->load(['driverProfile.currentVehicle', 'vehicles']));
    }

    /** PATCH /admin/drivers/{user}/status  {status: approved|suspended|rejected|pending, note?} */
    public function setStatus(Request $request, User $user, DriverLocationService $locations): UserResource
    {
        abort_unless($user->isDriver(), 404);
        $data = $request->validate([
            'status' => ['required', Rule::enum(DriverStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $profile = $user->driverProfile()->firstOrCreate([]);
        $profile->update([
            'status' => $data['status'],
            'status_note' => $data['note'] ?? null,
            'approved_at' => $data['status'] === DriverStatus::Approved->value ? now() : $profile->approved_at,
        ]);

        if ($data['status'] !== DriverStatus::Approved->value) {
            $profile->update(['is_online' => false]);
            $locations->goOffline($user);
        }

        return new UserResource($user->fresh()->load('driverProfile.currentVehicle'));
    }

    /** PATCH /admin/vehicles/{vehicle}/status */
    public function setVehicleStatus(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate(['status' => ['required', Rule::enum(VehicleStatus::class)]]);
        $vehicle->update(['status' => $data['status']]);

        return new VehicleResource($vehicle);
    }
}
