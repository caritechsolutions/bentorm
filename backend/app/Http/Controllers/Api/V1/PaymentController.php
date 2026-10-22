<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TripResource;
use App\Models\Gateway;
use App\Models\Payment;
use App\Models\Trip;
use App\Plugins\PaymentResult;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * POST /trips/{trip}/pay {payment_method_id?} — settle an amount owed. With a saved card it's charged directly;
     * otherwise a redirect_url to a hosted payment page is returned.
     */
    public function pay(Request $request, Trip $trip): JsonResponse
    {
        $data = $request->validate(['payment_method_id' => ['nullable', 'string', 'max:40']]);
        $payment = $this->payments->start($trip, $request->user(), $data['payment_method_id'] ?? null);

        return response()->json([
            'payment' => (new PaymentResource($payment->load('trip')))->resolve($request),
            'redirect_url' => $payment->status === 'pending' ? $payment->redirect_url : null,
        ], $payment->status === 'failed' ? 402 : 200);
    }

    /** GET /payments/{payment} — poll status after returning from the gateway. */
    public function show(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

        return new PaymentResource($payment->load(['trip', 'gateway']));
    }

    /** GET /me/outstanding — trips with money owed. */
    public function outstanding(Request $request): AnonymousResourceCollection
    {
        return TripResource::collection($request->user()->outstandingTrips()->latest('id')->get());
    }

    // ---------------------------------------------------------------- browser / gateway facing (no auth)

    /** GET|POST /payments/{payment}/return — the rider's browser lands here from the gateway. */
    public function return(Request $request, Payment $payment): View
    {
        $payment = $this->payments->handleReturn($payment->load('gateway'), $request);

        return view('payments.result', ['payment' => $payment, 'deepLink' => $this->deepLink($payment)]);
    }

    /** POST /webhooks/payments/{gateway} — server-to-server notification. */
    public function webhook(Request $request, Gateway $gateway): JsonResponse
    {
        $payment = $this->payments->handleWebhook($gateway, $request);

        return response()->json(['ok' => true, 'matched' => (bool) $payment]);
    }

    /** GET /payments/test/{payment} (signed) — test gateway's hosted page. */
    public function testCheckout(Payment $payment): View
    {
        abort_unless($payment->gateway?->plugin === 'test_payment', 404);
        abort_if($payment->isFinal() || $payment->status === 'authorized', 410, 'This payment is already complete.');

        $sign = fn (string $name) => URL::temporarySignedRoute($name, now()->addMinutes(30), ['payment' => $payment->public_id]);

        return view('payments.test-checkout', [
            'payment' => $payment,
            'approveUrl' => $sign('payments.test.approve'),
            'declineUrl' => $sign('payments.test.decline'),
        ]);
    }

    /** POST /payments/test/{payment}/approve|decline (signed) */
    public function testDecide(Request $request, Payment $payment): \Illuminate\Http\RedirectResponse
    {
        abort_unless($payment->gateway?->plugin === 'test_payment', 404);
        $approve = $request->route()->getName() === 'payments.test.approve';
        $this->payments->apply($payment, match (true) {
            ! $approve => PaymentResult::failed('Declined on the test page.', $payment->gateway_ref),
            $payment->isHold() => PaymentResult::authorized((float) $payment->amount, $payment->gateway_ref), // 3-D Secure passed
            default => PaymentResult::captured($payment->gateway_ref, [], (float) $payment->amount),
        });

        return redirect()->route('payments.return', ['payment' => $payment->public_id]);
    }

    private function deepLink(Payment $payment): string
    {
        return sprintf('%s://payment?id=%s&status=%s', config('bentorm.app_scheme'), $payment->public_id, $payment->status);
    }
}
