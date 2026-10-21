<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FareConfigResource;
use App\Models\FareConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FareAdminController extends Controller
{
    /** GET /admin/fares */
    public function index(): AnonymousResourceCollection
    {
        return FareConfigResource::collection(FareConfig::query()->orderBy('sort_order')->get());
    }

    /** PUT /admin/fares/{vehicle_type} — create or update a rate card row. */
    public function upsert(Request $request, string $vehicleType): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'base_fare' => ['required', 'numeric', 'min:0'],
            'per_km' => ['required', 'numeric', 'min:0'],
            'per_minute' => ['required', 'numeric', 'min:0'],
            'minimum_fare' => ['required', 'numeric', 'min:0'],
            'booking_fee' => ['nullable', 'numeric', 'min:0'],
            'cancellation_fee' => ['nullable', 'numeric', 'min:0'],
            'surge_multiplier' => ['nullable', 'numeric', 'min:1', 'max:10'],
            'max_passengers' => ['nullable', 'integer', 'min:1', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $fare = FareConfig::query()->updateOrCreate(['vehicle_type' => strtolower($vehicleType)], $data);

        return (new FareConfigResource($fare))->response()->setStatusCode($fare->wasRecentlyCreated ? 201 : 200);
    }
}
