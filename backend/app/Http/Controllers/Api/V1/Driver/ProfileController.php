<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /** GET /driver/profile */
    public function show(Request $request): DriverProfileResource
    {
        $profile = $request->user()->driverProfile()->firstOrCreate([]);

        return new DriverProfileResource($profile->load('currentVehicle'));
    }

    /** PATCH /driver/profile — licence details (re-submission puts a rejected profile back to pending). */
    public function update(Request $request): DriverProfileResource
    {
        $data = $request->validate([
            'licence_number' => ['sometimes', 'string', 'max:64'],
            'licence_expiry' => ['sometimes', 'date', 'after:today'],
            'licence_image_url' => ['nullable', 'url', 'max:500'],
            'psv_permit_number' => ['nullable', 'string', 'max:64'],
        ]);

        $profile = $request->user()->driverProfile()->firstOrCreate([]);
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
            'plate' => ['required', 'string', 'max:16', 'unique:vehicles,plate'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:16'],
            'vehicle_type' => ['required', Rule::exists('fare_configs', 'vehicle_type')],
            'documents' => ['nullable', 'array'],
        ]);
        $data['plate'] = strtoupper(preg_replace('/\s+/', '', $data['plate']));

        $vehicle = $request->user()->vehicles()->create($data);

        // First vehicle becomes the current one automatically.
        $profile = $request->user()->driverProfile()->firstOrCreate([]);
        if (! $profile->current_vehicle_id) {
            $profile->update(['current_vehicle_id' => $vehicle->id]);
        }

        return (new VehicleResource($vehicle))->response()->setStatusCode(201);
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
