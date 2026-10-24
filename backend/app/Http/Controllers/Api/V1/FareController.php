<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\DistanceProvider;
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
    public function estimate(Request $request, PricingService $pricing, DistanceProvider $distance): JsonResponse
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            'vehicle_type' => ['nullable', 'string', 'max:32'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:16'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:20'],
            'stops' => ['nullable', 'array', 'max:'.config('bentorm.max_stops', 2)],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'], // price a booking for later (time-of-day rates apply then)
            'promo_code' => ['nullable', 'string', 'max:32'], // preview the discount (checked again at booking)
        ]);
        $at = isset($data['scheduled_at']) ? \Carbon\CarbonImmutable::parse($data['scheduled_at']) : null;
        $stops = array_values($data['stops'] ?? []);
        $pax = (int) ($data['passengers'] ?? 1);
        $bags = (int) ($data['luggage'] ?? 0);
        [$pLat, $pLng, $dLat, $dLng] = [(float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng']];

        if (! empty($data['vehicle_type'])) {
            $q = $pricing->estimate($pLat, $pLng, $dLat, $dLng, $data['vehicle_type'], $at, $pax, $bags, $stops);
            unset($q['pickup_context'], $q['dropoff_context']);
            $quotes = [$q];
        } else {
            $quotes = $pricing->estimateAll($pLat, $pLng, $dLat, $dLng, $at, $pax, $bags, $stops);
        }

        if (filled($data['promo_code'] ?? null)) {
            $promo = app(\App\Services\PromoService::class)->check($data['promo_code'], $request->user('sanctum'), 'rides', (float) ($quotes[0]['fare'] ?? 0), $quotes[0]['service_area_id'] ?? null);
            $quotes = array_map(fn ($q) => $q + ['discount' => $promo->discountOn((float) $q['fare']), 'promo' => $promo->summary($q['currency'] ?? 'BBD')], $quotes);
        }

        $quotes = array_map(function ($q) use ($request) {
            unset($q['breakdown']['lock']);
            if (! $request->user()?->isAdmin()) {
                unset($q['platform_fee'], $q['driver_earnings']);
            }

            return $q;
        }, $quotes);

        // Same lookup the quotes used (cached for Google), returned so apps can draw the route.
        $route = $distance->route(PricingService::points($pLat, $pLng, $stops, $dLat, $dLng));

        return response()->json([
            'quotes' => $quotes,
            'route' => [
                'distance_m' => $route->distanceM,
                'duration_s' => $route->durationS,
                'polyline' => $route->polyline ? array_map(fn ($p) => [(float) $p[1], (float) $p[0]], $route->polyline) : null, // [[lat, lng], ...]
            ],
        ]);
    }
}
