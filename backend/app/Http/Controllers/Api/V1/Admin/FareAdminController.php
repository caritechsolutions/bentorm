<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AreaType;
use App\Enums\FareRuleKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\FareConfigResource;
use App\Http\Resources\FareRuleResource;
use App\Http\Resources\VehicleTypeResource;
use App\Models\FareConfig;
use App\Models\FareRule;
use App\Models\ServiceArea;
use App\Models\VehicleType;
use App\Services\PricingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FareAdminController extends Controller
{
    // ---------------------------------------------------------------- vehicle types

    /** GET /admin/vehicle-types */
    public function vehicleTypes(): AnonymousResourceCollection
    {
        return VehicleTypeResource::collection(VehicleType::query()->orderBy('sort_order')->get());
    }

    /** PUT /admin/vehicle-types/{key} */
    public function upsertVehicleType(Request $request, string $key): JsonResponse
    {
        validator(['key' => $key], ['key' => ['regex:/^[a-z0-9_]+$/', 'max:32']])->validate();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'max_passengers' => ['nullable', 'integer', 'min:1', 'max:16'],
            'icon_url' => ['nullable', 'url', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $vt = VehicleType::query()->updateOrCreate(['key' => $key], array_filter($data, fn ($v) => $v !== null));

        return (new VehicleTypeResource($vt))->response()->setStatusCode($vt->wasRecentlyCreated ? 201 : 200);
    }

    // ---------------------------------------------------------------- rate cards

    /** GET /admin/service-areas/{area}/fares */
    public function configs(ServiceArea $area): AnonymousResourceCollection
    {
        return FareConfigResource::collection($area->fareConfigs()->with('serviceArea')->orderBy('vehicle_type')->get());
    }

    /** PUT /admin/service-areas/{area}/fares/{vehicleType} — amounts exclude tax. */
    public function upsertConfig(Request $request, ServiceArea $area, string $vehicleType): JsonResponse
    {
        abort_unless($area->isService(), 422, 'Rate cards belong to service areas.');
        abort_unless(VehicleType::query()->where('key', $vehicleType)->exists(), 422, 'Unknown vehicle type.');
        $data = $request->validate([
            'base_fare' => ['required', 'numeric', 'min:0'],
            'per_km' => ['required', 'numeric', 'min:0'],
            'per_minute' => ['required', 'numeric', 'min:0'],
            'minimum_fare' => ['required', 'numeric', 'min:0'],
            'booking_fee' => ['nullable', 'numeric', 'min:0'],
            'cancellation_fee' => ['nullable', 'numeric', 'min:0'],
            'free_wait_minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
            'wait_per_minute' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $cfg = FareConfig::query()->updateOrCreate(
            ['service_area_id' => $area->id, 'vehicle_type' => $vehicleType],
            array_filter($data, fn ($v) => $v !== null),
        );

        return (new FareConfigResource($cfg->load('serviceArea')))->response()->setStatusCode($cfg->wasRecentlyCreated ? 201 : 200);
    }

    // ---------------------------------------------------------------- rules

    /** GET /admin/fare-rules?service_area_id=&kind= */
    public function rules(Request $request): AnonymousResourceCollection
    {
        $q = FareRule::query()->orderBy('service_area_id')->orderBy('kind')->orderByDesc('priority');
        if ($a = $request->integer('service_area_id')) {
            $q->where('service_area_id', $a);
        }
        if ($k = $request->query('kind')) {
            $q->where('kind', $k);
        }

        return FareRuleResource::collection($q->get());
    }

    /** POST /admin/fare-rules */
    public function storeRule(Request $request): JsonResponse
    {
        $rule = FareRule::query()->create($this->validateRule($request, true));

        return (new FareRuleResource($rule))->response()->setStatusCode(201);
    }

    /** PATCH /admin/fare-rules/{rule} */
    public function updateRule(Request $request, FareRule $rule): FareRuleResource
    {
        $rule->update($this->validateRule($request, false, $rule));

        return new FareRuleResource($rule->fresh());
    }

    /** DELETE /admin/fare-rules/{rule} */
    public function destroyRule(FareRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** POST /admin/fares/simulate — same as /fares/estimate but at any time, with commission and driver share. */
    public function simulate(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric'], 'pickup_lng' => ['required', 'numeric'],
            'dropoff_lat' => ['required', 'numeric'], 'dropoff_lng' => ['required', 'numeric'],
            'at' => ['nullable', 'date'],
        ]);
        $at = isset($data['at']) ? Carbon::parse($data['at']) : now();

        return response()->json(['at' => $at->toIso8601String(), 'quotes' => $pricing->estimateAll(
            (float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng'], $at,
        )]);
    }

    private function validateRule(Request $request, bool $creating, ?FareRule $existing = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'service_area_id' => [$req, Rule::exists('service_areas', 'id')->where('type', AreaType::Service->value)],
            'vehicle_type' => ['nullable', 'exists:vehicle_types,key'],
            'name' => [$req, 'string', 'max:128'],
            'kind' => [$req, Rule::enum(FareRuleKind::class)],
            'multiplier' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:0,6'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'pickup_zone_id' => ['nullable', Rule::exists('service_areas', 'id')->where('type', AreaType::Zone->value)],
            'dropoff_zone_id' => ['nullable', Rule::exists('service_areas', 'id')->where('type', AreaType::Zone->value)],
            'zone_applies_to' => ['nullable', 'in:pickup,dropoff,either'],
            'bidirectional' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $merged = array_merge($existing?->only(array_keys($existing->getAttributes())) ?? [], $data);
        $kind = FareRuleKind::from($merged['kind'] instanceof FareRuleKind ? $merged['kind']->value : $merged['kind']);
        $errors = [];
        match ($kind) {
            FareRuleKind::TimeMultiplier => empty($merged['multiplier']) && $errors['multiplier'] = 'A multiplier is required.',
            FareRuleKind::Surge => empty($merged['multiplier']) && $errors['multiplier'] = 'A multiplier is required.',
            FareRuleKind::ZoneFee => (! isset($merged['amount']) && $errors['amount'] = 'An amount is required.')
                || (empty($merged['pickup_zone_id']) && $errors['pickup_zone_id'] = 'Choose the zone (pickup_zone_id) the fee applies to.'),
            FareRuleKind::FixedRoute => (! isset($merged['amount']) && $errors['amount'] = 'An amount is required.')
                || ((empty($merged['pickup_zone_id']) || empty($merged['dropoff_zone_id'])) && $errors['pickup_zone_id'] = 'Fixed routes need pickup_zone_id and dropoff_zone_id.'),
        };
        if ($errors) {
            throw ValidationException::withMessages(array_map(fn ($m) => [$m], $errors));
        }

        return $data;
    }
}
