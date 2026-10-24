<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AreaType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceAreaResource;
use App\Http\Resources\VehicleTypeResource;
use App\Models\FareConfig;
use App\Models\ServiceArea;
use App\Models\VehicleType;
use App\Services\GeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GeoController extends Controller
{
    /** GET /service-areas — operating regions (for coverage maps and driver sign-up). */
    public function serviceAreas(): AnonymousResourceCollection
    {
        return ServiceAreaResource::collection(
            ServiceArea::query()->where('type', AreaType::Service->value)->where('is_active', true)->orderBy('name')->get()
        );
    }

    /** GET /geo/check?lat=&lng= — is this point served? Which zone / block applies? */
    public function check(Request $request, GeoService $geo): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        return response()->json($geo->resolve((float) $data['lat'], (float) $data['lng'])->toArray());
    }

    /** GET /vehicle-features — what riders can ask for. */
    public function vehicleFeatures(): JsonResponse
    {
        return response()->json(collect(config('bentorm.vehicle_features'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values());
    }

    /** GET /vehicle-types?service_area_id= — types with an active rate card in the area (or all). */
    public function vehicleTypes(Request $request): AnonymousResourceCollection
    {
        $q = VehicleType::query()->rides()->where('is_active', true)->orderBy('sort_order');
        if ($areaId = $request->integer('service_area_id')) {
            $q->whereIn('key', FareConfig::query()->where('service_area_id', $areaId)->where('is_active', true)->pluck('vehicle_type'));
        }

        return VehicleTypeResource::collection($q->get());
    }
}
