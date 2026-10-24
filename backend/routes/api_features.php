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
