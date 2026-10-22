<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentTypeResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ComplianceService;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DriverAdminController extends Controller
{
    public function __construct(private readonly ComplianceService $compliance) {}

    /** GET /admin/drivers?status=pending&service_area_id=&q= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = User::query()->where('role', 'driver')->with(['driverProfile.currentVehicle']);
        if ($status = $request->query('status')) {
            $q->whereHas('driverProfile', fn ($p) => $p->where('status', $status));
        }
        if ($area = $request->integer('service_area_id')) {
            $q->whereHas('driverProfile', fn ($p) => $p->where('service_area_id', $area));
        }
        if ($search = $request->query('q')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        return UserResource::collection($q->latest('id')->paginate(25));
    }

    /** GET /admin/drivers/{user} — full application: profile, vehicles, document checklists, blockers. */
    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isDriver(), 404);
        $user->load(['driverProfile.currentVehicle', 'vehicles']);
        $profile = $user->driverProfile()->firstOrCreate([]);

        return response()->json([
            'driver' => (new UserResource($user))->resolve($request),
            'driver_issues' => $this->compliance->driverIssues($profile),
            'documents' => $this->checklist($request, $profile),
            'vehicles' => $user->vehicles->map(fn (Vehicle $v) => [
                'vehicle' => (new VehicleResource($v))->resolve($request),
                'issues' => $this->compliance->vehicleIssues($v),
                'documents' => $this->checklist($request, $v),
            ])->values(),
            'can_go_online_issues' => $this->compliance->canGoOnline($user),
        ]);
    }

    /** PATCH /admin/drivers/{user}/status  {status, note?, force?} — approval requires all required documents unless force. */
    public function setStatus(Request $request, User $user, DriverLocationService $locations): JsonResponse
    {
        abort_unless($user->isDriver(), 404);
        $data = $request->validate([
            'status' => ['required', Rule::enum(DriverStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'force' => ['nullable', 'boolean'],
        ]);
        $profile = $user->driverProfile()->firstOrCreate([]);

        if ($data['status'] === DriverStatus::Approved->value && ! ($data['force'] ?? false)) {
            $issues = $this->compliance->driverIssues($profile);
            if ($issues) {
                return response()->json(['message' => 'Driver cannot be approved yet.', 'issues' => $issues, 'hint' => 'Send force=true to override.'], 422);
            }
        }

        $profile->update([
            'status' => $data['status'],
            'status_note' => $data['note'] ?? null,
            'approved_at' => $data['status'] === DriverStatus::Approved->value ? now() : $profile->approved_at,
            'approved_by' => $data['status'] === DriverStatus::Approved->value ? $request->user()->id : $profile->approved_by,
        ]);

        if ($data['status'] !== DriverStatus::Approved->value && $profile->is_online && ! $user->activeTrip()) {
            $profile->update(['is_online' => false]);
            $locations->goOffline($user);
        }

        return response()->json(['driver' => (new UserResource($user->fresh()->load('driverProfile.currentVehicle')))->resolve($request)]);
    }

    /** PATCH /admin/vehicles/{vehicle}/status  {status, note?, force?} */
    public function setVehicleStatus(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(VehicleStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'force' => ['nullable', 'boolean'],
        ]);
        if ($data['status'] === VehicleStatus::Approved->value && ! ($data['force'] ?? false)) {
            $issues = $this->compliance->vehicleIssues($vehicle);
            if ($issues) {
                return response()->json(['message' => 'Vehicle cannot be approved yet.', 'issues' => $issues, 'hint' => 'Send force=true to override.'], 422);
            }
        }
        $vehicle->update([
            'status' => $data['status'],
            'status_note' => $data['note'] ?? null,
            'approved_at' => $data['status'] === VehicleStatus::Approved->value ? now() : $vehicle->approved_at,
        ]);

        return response()->json(['vehicle' => (new VehicleResource($vehicle))->resolve($request)]);
    }

    private function checklist(Request $request, $owner): array
    {
        return array_map(fn ($row) => [
            'type' => (new DocumentTypeResource($row['type']))->resolve($request),
            'state' => $row['state'],
            'document' => $row['document'] ? (new DocumentResource($row['document']->load('type')))->resolve($request) : null,
            'pending_replacement' => $row['pending_replacement'] ? (new DocumentResource($row['pending_replacement']->load('type')))->resolve($request) : null,
        ], $this->compliance->checklist($owner));
    }
}
