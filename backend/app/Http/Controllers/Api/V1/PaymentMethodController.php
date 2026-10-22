<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Plugins\Payment\TestPayment;
use App\Services\GeoService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** GET /me/payment-methods */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaymentMethodResource::collection(
            $request->user()->paymentMethods()->where('status', 'active')->orderByDesc('is_default')->latest('id')->get()
        );
    }

    /**
     * POST /me/payment-methods {service_area_id? | lat?, lng?} — start adding a card.
     * Returns a redirect_url to open in a browser / webview; the page returns to ridecoop://card?id=&status=.
     */
    public function store(Request $request, GeoService $geo): JsonResponse
    {
        $data = $request->validate([
            'service_area_id' => ['nullable', 'exists:service_areas,id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $areaId = $data['service_area_id'] ?? (isset($data['lat']) ? $geo->resolve((float) $data['lat'], (float) $data['lng'])->service?->id : null);

        $pm = $this->payments->startCardSetup($request->user(), $areaId);

        return response()->json([
            'payment_method' => (new PaymentMethodResource($pm))->resolve($request),
            'redirect_url' => $pm->status === 'pending' ? $pm->redirect_url : null,
        ], $pm->status === 'failed' ? 422 : 201);
    }

    /** GET /me/payment-methods/{paymentMethod} — poll after the setup page. */
    public function show(Request $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        abort_unless($paymentMethod->user_id === $request->user()->id, 404);

        return new PaymentMethodResource($paymentMethod);
    }

    /** POST /me/payment-methods/{paymentMethod}/default */
    public function makeDefault(Request $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        abort_unless($paymentMethod->user_id === $request->user()->id, 404);

        return new PaymentMethodResource($this->payments->setDefault($paymentMethod));
    }

    /** DELETE /me/payment-methods/{paymentMethod} */
    public function destroy(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        abort_unless($paymentMethod->user_id === $request->user()->id, 404);
        $this->payments->remove($paymentMethod);

        return response()->json(['message' => 'Card removed.']);
    }

    // ---------------------------------------------------------------- browser-facing (no auth)

    /** GET|POST /payment-methods/{paymentMethod}/return — the gateway's card page sends the browser here. */
    public function return(Request $request, PaymentMethod $paymentMethod): View
    {
        $pm = $this->payments->completeCardSetup($paymentMethod->load('gateway'), $request);

        return view('payments.card-result', [
            'pm' => $pm,
            'deepLink' => sprintf('%s://card?id=%s&status=%s', config('bentorm.app_scheme'), $pm->public_id, $pm->status),
        ]);
    }

    /** GET /payment-methods/test/{paymentMethod} (signed) — test gateway's card entry page. */
    public function testForm(PaymentMethod $paymentMethod): View
    {
        abort_unless($paymentMethod->gateway?->plugin === 'test_payment' && $paymentMethod->status === 'pending', 404);

        return view('payments.test-card', [
            'pm' => $paymentMethod,
            'cards' => TestPayment::CARDS,
            'action' => URL::temporarySignedRoute('payment-methods.test.submit', now()->addMinutes(30), ['paymentMethod' => $paymentMethod->public_id]),
        ]);
    }

    /** POST /payment-methods/test/{paymentMethod} (signed) */
    public function testSubmit(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        abort_unless($paymentMethod->gateway?->plugin === 'test_payment' && $paymentMethod->status === 'pending', 404);
        $data = $request->validate([
            'number' => ['required', 'string'],
            'exp_month' => ['required', 'integer', 'between:1,12'],
            'exp_year' => ['required', 'integer', 'min:'.now()->year],
            'name' => ['nullable', 'string', 'max:128'],
        ]);
        $paymentMethod->forceFill(['payload' => ['test_card' => $data]])->save();

        return redirect()->route('payment-methods.return', ['paymentMethod' => $paymentMethod->public_id]);
    }
}
