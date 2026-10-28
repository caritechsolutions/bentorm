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
        $fleets = [];
        foreach ($periods as $name => $from) {
            $all = Trip::query()->where('driver_id', $request->user()->id)->where('updated_at', '>=', $from->utc())
                ->where(fn ($w) => $w->where('status', TripStatus::Completed->value)
                    ->orWhere(fn ($c) => $c->where('status', TripStatus::Cancelled->value)->where('cancellation_fee', '>', 0)));
            // Trips in a fleet's car earn for the fleet, not the driver.
            $q = (clone $all)->whereNull('fleet_id');
            $out[$name] = [
                'trips' => (clone $q)->where('status', TripStatus::Completed->value)->count(),
                'earnings' => round((float) (clone $q)->sum('driver_earnings') + (float) (clone $q)->sum('tip_amount'), 2),
                'tips' => round((float) (clone $q)->sum('tip_amount'), 2),
                'cash_collected' => round((float) (clone $q)->where('payment_method', 'cash')->where('status', TripStatus::Completed->value)->sum('final_fare'), 2),
            ];
            foreach ((clone $all)->whereNotNull('fleet_id')->with('fleet:id,name')->get() as $t) {
                $f = &$fleets[$t->fleet_id];
                $f ??= ['fleet' => $t->fleet?->name ?? 'Fleet', 'periods' => []];
                $p = &$f['periods'][$name];
                $p ??= ['trips' => 0, 'earnings' => 0.0, 'cash_collected' => 0.0];
                $p['trips'] += $t->status === TripStatus::Completed ? 1 : 0;
                $p['earnings'] = round($p['earnings'] + (float) $t->driver_earnings + (float) $t->tip_amount, 2);
                if ($t->status === TripStatus::Completed && $t->payment_method?->value === 'cash') {
                    $p['cash_collected'] = round($p['cash_collected'] + (float) $t->final_fare, 2);
                }
                unset($f, $p);
            }
        }

        return response()->json(['currency' => $request->user()->driverProfile?->serviceArea?->currency ?? 'BBD', 'periods' => $out,
            // Earned in fleet cars — paid to the fleet: [{fleet, periods: {today: {trips, earnings, cash_collected}, …}}]
            'fleets' => array_values($fleets)]);
    }
}
