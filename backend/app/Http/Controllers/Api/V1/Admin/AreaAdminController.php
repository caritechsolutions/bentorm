<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AreaType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceAreaResource;
use App\Models\ServiceArea;
use App\Services\GeoService;
use App\Support\GeoJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AreaAdminController extends Controller
{
    /** GET /admin/service-areas?type=&parent_id= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ServiceArea::query()->orderBy('type')->orderByDesc('priority')->orderBy('name');
        if ($t = $request->query('type')) {
            $q->where('type', $t);
        }
        if ($request->has('parent_id')) {
            $q->where('parent_id', $request->integer('parent_id') ?: null);
        }

        return ServiceAreaResource::collection($q->get());
    }

    /** GET /admin/service-areas/{area} */
    public function show(ServiceArea $area): ServiceAreaResource
    {
        return new ServiceAreaResource($area->load('children'));
    }

    /** POST /admin/service-areas — polygon is GeoJSON Polygon/MultiPolygon ([lng, lat]) or a list of {lat, lng}. */
    public function store(Request $request): JsonResponse
    {
        $area = ServiceArea::query()->create($this->validated($request, true));

        return (new ServiceAreaResource($area))->response()->setStatusCode(201);
    }

    /** PATCH /admin/service-areas/{area} */
    public function update(Request $request, ServiceArea $area): ServiceAreaResource
    {
        $area->update($this->validated($request, false, $area));

        return new ServiceAreaResource($area->fresh());
    }

    /** DELETE /admin/service-areas/{area} — service areas with trips should be deactivated instead. */
    public function destroy(ServiceArea $area): JsonResponse
    {
        if ($area->isService() && \App\Models\Trip::query()->where('service_area_id', $area->id)->exists()) {
            abort(409, 'This area has trips. Set is_active=false instead of deleting it.');
        }
        $area->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** POST /admin/geo/check {lat, lng} — includes inactive-cache bypass for testing edits. */
    public function check(Request $request, GeoService $geo): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        return response()->json($geo->resolve((float) $data['lat'], (float) $data['lng'])->toArray());
    }

    private function validated(Request $request, bool $creating, ?ServiceArea $existing = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'type' => [$req, Rule::enum(AreaType::class)],
            'parent_id' => ['nullable', 'exists:service_areas,id'],
            'code' => [$req, 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('service_areas', 'code')->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:128'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'timezone'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'tax_label' => ['nullable', 'string', 'max:32'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'rounding_increment' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hold_buffer_rate' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'polygon' => [$req],
            'message' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('polygon', $data)) {
            try {
                $data['polygon'] = GeoJson::normalise($data['polygon']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['polygon' => [$e->getMessage()]]);
            }
        }

        $type = AreaType::from($data['type'] ?? $existing?->type->value);
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $existing?->parent_id;

        if ($type === AreaType::Service) {
            foreach (['currency', 'timezone'] as $f) {
                if (empty($data[$f] ?? $existing?->{$f})) {
                    throw ValidationException::withMessages([$f => ["A service area needs a {$f}."]]);
                }
            }
            $data['parent_id'] = null;
            if (isset($data['country_code'])) {
                $data['country_code'] = strtoupper($data['country_code']);
            }
            if (isset($data['currency'])) {
                $data['currency'] = strtoupper($data['currency']);
            }
        }
        if ($type === AreaType::Zone) {
            $parent = $parentId ? ServiceArea::query()->find($parentId) : null;
            if (! $parent || ! $parent->isService()) {
                throw ValidationException::withMessages(['parent_id' => ['A zone must belong to a service area.']]);
            }
        }

        return $data;
    }
}
