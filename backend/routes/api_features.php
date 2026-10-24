<?php

use App\Http\Controllers\Api\V1\AppLinkController;
use Illuminate\Support\Facades\Route;

/* Included inside the authenticated (auth:sanctum) group of routes/api.php, under /api/v1. */

// Web pages inside the apps: a one-time sign-in link.
Route::post('app-links', [AppLinkController::class, 'store'])->middleware('throttle:30,1');

// Saved places (Home, Work, others)
Route::get('me/places', [\App\Http\Controllers\Api\V1\SavedPlaceController::class, 'index']);
Route::post('me/places', [\App\Http\Controllers\Api\V1\SavedPlaceController::class, 'store']);
Route::patch('me/places/{place}', [\App\Http\Controllers\Api\V1\SavedPlaceController::class, 'update']);
Route::delete('me/places/{place}', [\App\Http\Controllers\Api\V1\SavedPlaceController::class, 'destroy']);

// Tips (100% to the driver)
Route::post('trips/{trip}/tip', function (\Illuminate\Http\Request $request, \App\Models\Trip $trip, \App\Services\TipService $tips) {
    $d = $request->validate(['amount' => ['required', 'numeric'], 'payment_method_id' => ['nullable', 'string', 'max:40']]);

    return new \App\Http\Resources\TripResource($tips->tip($trip, $request->user(), (float) $d['amount'], $d['payment_method_id'] ?? null)->load(['driver', 'vehicle']));
})->middleware('throttle:10,1');
Route::post('shop/orders/{order}/tip', function (\Illuminate\Http\Request $request, \App\Models\Order $order, \App\Services\TipService $tips) {
    abort_unless($order->customer_id === $request->user()->id, 404);
    $d = $request->validate(['amount' => ['required', 'numeric']]);
    $trip = $order->deliveries()->where('status', 'completed')->latest('id')->first();
    abort_unless($trip, 409, 'You can tip once your order has been delivered.');
    $tips->tip($trip, $request->user(), (float) $d['amount']);

    return new \App\Http\Resources\OrderResource($order->fresh(['items', 'store', 'card', 'payments']));
})->middleware('throttle:10,1');

// Ride credit, invite a friend
Route::get('me/credit', function (\Illuminate\Http\Request $request) {
    $u = $request->user();

    return [
        'balance' => \App\Models\CreditEntry::balance($u->id),
        'currency' => 'BBD',
        'max_share' => \App\Support\Features::number('credit.max_share'),
        'entries' => \App\Models\CreditEntry::query()->where('user_id', $u->id)->latest('id')->limit(50)->get()
            ->map(fn (\App\Models\CreditEntry $e) => ['amount' => $e->amount, 'label' => $e->label(), 'date' => $e->created_at?->toIso8601String()]),
    ];
});
Route::get('me/referral', function (\Illuminate\Http\Request $request, \App\Services\ReferralService $refs, \App\Services\PromoService $promos) {
    \App\Support\Features::require('referrals', 'Invite a friend');
    $u = $request->user();
    $code = $refs->codeFor($u);

    return [
        'code' => $code,
        'share_text' => sprintf('Ride with Ride Coop — Barbados\' own ride app. Use my code %s and we both get %.2f ride credit after your first trip.', $code, \App\Support\Features::number('referral.referee_credit')),
        'referrer_credit' => \App\Support\Features::number('referral.referrer_credit'),
        'referee_credit' => \App\Support\Features::number('referral.referee_credit'),
        'invited' => \App\Models\User::query()->where('referred_by_id', $u->id)->count(),
        'rewarded' => \App\Models\User::query()->where('referred_by_id', $u->id)->whereNotNull('referral_rewarded_at')->count(),
        'can_enter_code' => ! $u->referred_by_id && $promos->isNewCustomer($u) && $u->created_at?->gt(now()->subDays(\App\Services\ReferralService::DAYS_TO_ENTER)),
        'referred_by' => $u->referredBy?->firstName(),
    ];
});
Route::post('me/referral', function (\Illuminate\Http\Request $request, \App\Services\ReferralService $refs) {
    $d = $request->validate(['code' => ['required', 'string', 'max:12']]);
    $refs->apply($request->user(), $d['code']);

    return ['message' => 'Invite code added. You both get ride credit after your first trip or order.'];
})->middleware('throttle:10,1');
// Check a promo code before booking / checkout
Route::post('promo-codes/check', function (\Illuminate\Http\Request $request, \App\Services\PromoService $promos) {
    $d = $request->validate(['code' => ['required', 'string', 'max:32'], 'for' => ['required', 'in:rides,shop'], 'amount' => ['nullable', 'numeric', 'min:0']]);
    $p = $promos->check($d['code'], $request->user(), $d['for'], (float) ($d['amount'] ?? 1e9), null);

    return ['code' => $p->code, 'summary' => $p->summary(), 'description' => $p->description,
        'discount' => isset($d['amount']) ? $p->discountOn((float) $d['amount']) : null];
})->middleware('throttle:20,1');

// Safety: share my trip, SOS
Route::post('trips/{trip}/share', function (\Illuminate\Http\Request $request, \App\Models\Trip $trip, \App\Services\SafetyService $safety) {
    $url = $safety->shareLink($trip, $request->user());

    return ['url' => $url, 'text' => sprintf('Follow my %s live on Ride Coop: %s', $trip->order_id ? 'delivery' : 'ride', $url)];
})->middleware('throttle:20,1');
Route::post('sos', function (\Illuminate\Http\Request $request, \App\Services\SafetyService $safety) {
    $d = $request->validate(['trip_id' => ['nullable', 'string'], 'lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180']]);
    $trip = isset($d['trip_id']) ? \App\Models\Trip::query()->where('public_id', $d['trip_id'])->first() : $request->user()->activeTrip();
    $alert = $safety->sos($request->user(), $trip, isset($d['lat']) ? (float) $d['lat'] : null, isset($d['lng']) ? (float) $d['lng'] : null);

    return ['alert_id' => $alert->id, 'message' => 'Ride Coop has been alerted and can see where you are. If you are in danger, call the police now.',
        'emergency_numbers' => \App\Services\SafetyService::emergencyNumbers(), 'share_url' => $trip?->share_token ? url('/t/'.$trip->share_token) : null];
})->middleware('throttle:10,1');

// Chat between rider (or customer) and driver
Route::get('trips/{trip}/messages', fn (\Illuminate\Http\Request $request, \App\Models\Trip $trip, \App\Services\ChatService $chat) => [
    'messages' => $chat->list($trip, $request->user(), (int) $request->query('after', 0)),
    'open' => $chat->canChat($trip),
]);
Route::post('trips/{trip}/messages', function (\Illuminate\Http\Request $request, \App\Models\Trip $trip, \App\Services\ChatService $chat) {
    $d = $request->validate(['body' => ['required', 'string', 'max:500']]);

    return response()->json($chat->send($trip, $request->user(), $d['body'])->load('sender')->toApi($request->user()), 201);
})->middleware('throttle:30,1');

// In-app calls (WebRTC audio/video) with a phone-bridge fallback
Route::post('trips/{trip}/calls', function (\Illuminate\Http\Request $request, \App\Models\Trip $trip, \App\Services\CallService $calls) {
    $d = $request->validate(['video' => ['nullable', 'boolean']]);

    return response()->json($calls->toApi($calls->start($trip, $request->user(), (bool) ($d['video'] ?? false)), $request->user()), 201);
})->middleware('throttle:20,1');
Route::get('calls/incoming', function (\Illuminate\Http\Request $request, \App\Services\CallService $calls) {
    $calls->expireRinging();
    $c = \App\Models\Call::query()->where('callee_id', $request->user()->id)->where('status', 'ringing')->latest('id')->first();

    return ['call' => $c ? $calls->toApi($c, $request->user()) : null];
});
Route::get('calls/{call}', function (\Illuminate\Http\Request $request, \App\Models\Call $call, \App\Services\CallService $calls) {
    abort_unless($call->involves($request->user()), 404);
    $calls->expireRinging();

    return $calls->toApi($call->fresh(), $request->user());
});
Route::post('calls/{call}/{action}', function (\Illuminate\Http\Request $request, \App\Models\Call $call, string $action, \App\Services\CallService $calls) {
    $u = $request->user();
    $call = match ($action) {
        'accept' => $calls->answer($call, $u, true),
        'decline' => $calls->answer($call, $u, false),
        'end' => $calls->end($call, $u),
        'phone' => $calls->phoneBridge($call, $u),
    };

    return $calls->toApi($call, $u);
})->whereIn('action', ['accept', 'decline', 'end', 'phone'])->middleware('throttle:30,1');
Route::post('calls/{call}/signal', function (\Illuminate\Http\Request $request, \App\Models\Call $call, \App\Services\CallService $calls) {
    $d = $request->validate(['type' => ['required', 'in:offer,answer,candidate'], 'payload' => ['required', 'array']]);
    $calls->signal($call, $request->user(), $d['type'], $d['payload']);

    return ['ok' => true];
})->middleware('throttle:300,1');
Route::get('calls/{call}/signals', fn (\Illuminate\Http\Request $request, \App\Models\Call $call, \App\Services\CallService $calls) => [
    'signals' => $calls->signals($call, $request->user(), (int) $request->query('after', 0)),
    'status' => $call->fresh()->status,
]);
