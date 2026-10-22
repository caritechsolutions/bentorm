<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TripResource;
use App\Models\Payment;
use App\Models\Trip;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentAdminController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** GET /admin/payments?status=&method=&trip_id= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Payment::query()->with(['trip', 'gateway']);
        foreach (['status', 'method', 'purpose'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }
        if ($t = $request->query('trip_id')) {
            $q->whereHas('trip', fn ($w) => $w->where('public_id', $t));
        }

        return PaymentResource::collection($q->latest('id')->paginate(50));
    }

    /** POST /admin/payments/{payment}/refund {amount?} */
    public function refund(Request $request, Payment $payment): PaymentResource
    {
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01', 'max:'.$payment->amount]]);

        return new PaymentResource($this->payments->refund($payment->load('gateway'), $data['amount'] ?? null)->load(['trip', 'gateway']));
    }

    /** POST /admin/trips/{trip}/waive {reason} — forgive an amount owed. */
    public function waive(Request $request, Trip $trip): TripResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new TripResource($this->payments->waive($trip, $request->user(), $data['reason']));
    }
}
