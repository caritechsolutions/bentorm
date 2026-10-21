<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FareConfigResource;
use App\Models\FareConfig;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FareController extends Controller
{
    /** GET /fares — public rate card. */
    public function index(): AnonymousResourceCollection
    {
        return FareConfigResource::collection(FareConfig::query()->where('is_active', true)->orderBy('sort_order')->get());
    }

    /** POST /fares/estimate — quotes for all vehicle types (or one). */
    public function estimate(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            'vehicle_type' => ['nullable', 'string', 'max:32'],
        ]);

        $quotes = isset($data['vehicle_type'])
            ? [$pricing->estimate($data['pickup_lat'], $data['pickup_lng'], $data['dropoff_lat'], $data['dropoff_lng'], $data['vehicle_type'])]
            : $pricing->estimateAll($data['pickup_lat'], $data['pickup_lng'], $data['dropoff_lat'], $data['dropoff_lng']);

        return response()->json(['quotes' => $quotes]);
    }
}
