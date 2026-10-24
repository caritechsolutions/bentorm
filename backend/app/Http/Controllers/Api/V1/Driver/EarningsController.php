<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarningsController extends Controller
{
    /** GET /driver/earnings — today / this week / this month (driver's share, ex tax and commission). */
    public function summary(Request $request): JsonResponse
    {
        $tz = $request->user()->driverProfile?->serviceArea?->timezone ?: config('app.timezone');
        $now = now($tz);
        $periods = [
            'today' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfWeek(),
            'month' => $now->copy()->startOfMonth(),
        ];

        $out = [];
        foreach ($periods as $name => $from) {
            $q = Trip::query()->where('driver_id', $request->user()->id)->where('updated_at', '>=', $from->utc())
                ->where(fn ($w) => $w->where('status', TripStatus::Completed->value)
                    ->orWhere(fn ($c) => $c->where('status', TripStatus::Cancelled->value)->where('cancellation_fee', '>', 0)));
            $out[$name] = [
                'trips' => (clone $q)->where('status', TripStatus::Completed->value)->count(),
                'earnings' => round((float) (clone $q)->sum('driver_earnings') + (float) (clone $q)->sum('tip_amount'), 2),
                'tips' => round((float) (clone $q)->sum('tip_amount'), 2),
                'cash_collected' => round((float) (clone $q)->where('payment_method', 'cash')->where('status', TripStatus::Completed->value)->sum('final_fare'), 2),
            ];
        }

        return response()->json(['currency' => $request->user()->driverProfile?->serviceArea?->currency ?? 'BBD', 'periods' => $out]);
    }
}
