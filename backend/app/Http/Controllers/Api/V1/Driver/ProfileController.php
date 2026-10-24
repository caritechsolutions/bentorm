<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\AreaType;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Services\ComplianceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct(private readonly ComplianceService $compliance) {}

    /** GET /driver/profile — profile plus what's blocking going online. */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->driverProfile()->firstOrCreate([]);

        return response()->json([
            'profile' => (new DriverProfileResource($profile->load('currentVehicle')))->resolve($request),
            'can_go_online' => ($issues = $this->compliance->canGoOnline($request->user()->fresh())) === [],
            'issues' => $issues,
        ]);
    }

    /** PATCH /driver/profile */
    public function update(Request $request): DriverProfileResource
    {
        $data = $request->validate([
            'service_area_id' => ['sometimes', Rule::exists('service_areas', 'id')->where('type', AreaType::Service->value)->where('is_active', true)],
            'licence_number' => ['sometimes', 'string', 'max:64'],
            'licence_expiry' => ['sometimes', 'date', 'after:today'],
            'psv_permit_number' => ['nullable', 'string', 'max:64'],
        ]);

        $profile = $request->user()->driverProfile()->firstOrCreate([]);
        if ($profile->is_online && isset($data['service_area_id']) && $data['service_area_id'] != $profile->service_area_id) {
            abort(409, 'Go offline before changing your area.');
        }
        // Re-applying after a rejection puts the application back in the queue.
        if ($profile->status->value === 'rejected') {
            $data['status'] = 'pending';
            $data['status_note'] = null;
        }
        $profile->update($data);

        return new DriverProfileResource($profile->fresh()->load('currentVehicle'));
    }

    /** GET /driver/vehicles */
    public function vehicles(Request $request): AnonymousResourceCollection
    {
        return VehicleResource::collection($request->user()->vehicles()->latest('id')->get());
    }

    /** POST /driver/vehicles */
    public function storeVehicle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'make' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:64'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:'.(now()->year + 1)],
            'colour' => ['nullable', 'string', 'max:32'],
            'plate' => ['required', 'string', 'max:16'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:16'],
            'luggage_capacity' => ['nullable', 'integer', 'min:0', 'max:20'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(array_keys(config('bentorm.vehicle_features')))],
            'vehicle_type' => ['required', Rule::exists('vehicle_types', 'key')->where('is_active', true)->where('service', 'ride')],
        ]);
        $data['plate'] = strtoupper(preg_replace('/[\s-]+/', '', $data['plate']));
        validator($data, ['plate' => ['unique:vehicles,plate']], ['plate.unique' => 'This plate is already registered.'])->validate();

        $vehicle = $request->user()->vehicles()->create($data + ['status' => VehicleStatus::Pending]);

        $profile = $request->user()->driverProfile()->firstOrCreate([]);
        if (! $profile->current_vehicle_id) {
            $profile->update(['current_vehicle_id' => $vehicle->id]);
        }

        return (new VehicleResource($vehicle))->response()->setStatusCode(201);
    }

    /** PATCH /driver/vehicles/{vehicle} — edits send an approved vehicle back for review. */
    public function updateVehicle(Request $request, Vehicle $vehicle): VehicleResource
    {
        abort_unless($vehicle->driver_id === $request->user()->id, 403, 'Not your vehicle.');
        $data = $request->validate([
            'make' => ['sometimes', 'string', 'max:64'],
            'model' => ['sometimes', 'string', 'max:64'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:'.(now()->year + 1)],
            'colour' => ['nullable', 'string', 'max:32'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:16'],
            'luggage_capacity' => ['nullable', 'integer', 'min:0', 'max:20'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(array_keys(config('bentorm.vehicle_features')))],
            'vehicle_type' => ['sometimes', Rule::exists('vehicle_types', 'key')->where('is_active', true)->where('service', 'ride')],
        ]);
        if ($request->user()->driverProfile?->is_online && $request->user()->driverProfile->current_vehicle_id === $vehicle->id) {
            abort(409, 'Go offline before editing your current vehicle.');
        }
        $vehicle->fill($data);
        if ($vehicle->isDirty() && $vehicle->status === VehicleStatus::Approved) {
            $vehicle->status = VehicleStatus::Pending;
        }
        $vehicle->save();

        return new VehicleResource($vehicle);
    }

    /** POST /driver/vehicles/{vehicle}/select — set as current vehicle. */
    public function selectVehicle(Request $request, Vehicle $vehicle): DriverProfileResource
    {
        abort_unless($vehicle->driver_id === $request->user()->id, 403, 'Not your vehicle.');
        $profile = $request->user()->driverProfile()->firstOrCreate([]);
        if ($profile->is_online) {
            abort(409, 'Go offline before switching vehicles.');
        }
        $profile->update(['current_vehicle_id' => $vehicle->id]);

        return new DriverProfileResource($profile->fresh()->load('currentVehicle'));
    }
}
