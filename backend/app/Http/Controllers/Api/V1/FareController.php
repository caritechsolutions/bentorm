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
    /** GET /fares?service_area_id= — public rate cards. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = FareConfig::query()->with('serviceArea')->where('is_active', true);
        if ($areaId = $request->integer('service_area_id')) {
            $q->where('service_area_id', $areaId);
        }

        return FareConfigResource::collection($q->orderBy('service_area_id')->orderBy('vehicle_type')->get());
    }

    /** POST /fares/estimate — quotes for every vehicle type offered at the pickup (or one type). */
    public function estimate(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            'vehicle_type' => ['nullable', 'string', 'max:32'],
        ]);
        [$pLat, $pLng, $dLat, $dLng] = [(float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng']];

        if (! empty($data['vehicle_type'])) {
            $q = $pricing->estimate($pLat, $pLng, $dLat, $dLng, $data['vehicle_type']);
            unset($q['pickup_context'], $q['dropoff_context']);
            $quotes = [$q];
        } else {
            $quotes = $pricing->estimateAll($pLat, $pLng, $dLat, $dLng);
        }

        $quotes = array_map(function ($q) use ($request) {
            unset($q['breakdown']['lock']);
            if (! $request->user()?->isAdmin()) {
                unset($q['platform_fee'], $q['driver_earnings']);
            }

            return $q;
        }, $quotes);

        return response()->json(['quotes' => $quotes]);
    }
}
