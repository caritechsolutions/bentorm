<?php

use App\Http\Controllers\Api\V1\Admin\AreaAdminController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DocumentAdminController;
use App\Http\Controllers\Api\V1\Admin\DriverAdminController;
use App\Http\Controllers\Api\V1\Admin\FareAdminController;
use App\Http\Controllers\Api\V1\Admin\GatewayAdminController;
use App\Http\Controllers\Api\V1\Admin\PaymentAdminController;
use App\Http\Controllers\Api\V1\Admin\TripAdminController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Driver\DocumentController as DriverDocumentController;
use App\Http\Controllers\Api\V1\Driver\EarningsController;
use App\Http\Controllers\Api\V1\Driver\ProfileController as DriverProfileController;
use App\Http\Controllers\Api\V1\Driver\StatusController as DriverStatusController;
use App\Http\Controllers\Api\V1\Driver\TripController as DriverTripController;
use App\Http\Controllers\Api\V1\FareController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\Rider\TripController as RiderTripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---------------------------------------------------------------- public
    Route::get('health', fn () => ['ok' => true, 'time' => now()->toIso8601String()]);
    // Runtime settings for the apps (nothing secret: the Reverb app key is public by design).
    Route::get('config', fn () => [
        'realtime' => [
            'key' => config('reverb.apps.apps.0.key'),
            'host' => config('reverb.apps.apps.0.options.host'),
            'port' => (int) config('reverb.apps.apps.0.options.port'),
            'scheme' => config('reverb.apps.apps.0.options.scheme'),
            'auth_endpoint' => url('/api/broadcasting/auth'),
        ],
        'address_search' => \App\Services\Google\PlacesService::configured(),
        'app_scheme' => config('bentorm.app_scheme'),
        'otp_length' => 6,
        // Driver app: show in-app turn-by-turn (each start is still checked with POST /driver/trips/{id}/navigation).
        'navigation' => ['in_app' => app(\App\Services\NavigationPolicy::class)->blockedReason() === null],
        // Feature switches (Settings → App features): the apps hide what is off, no new build needed.
        'features' => \App\Support\Features::forApps(),
    ]);
    // Shop browsing is public (the app shows stores before sign-in).
    Route::get('shop/categories', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'categories']);
    Route::get('shop/stores', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'stores']);
    Route::get('shop/stores/{slug}', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'store']);
    Route::post('shop/quote', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'quote'])->middleware('throttle:60,1');
    Route::get('service-areas', [GeoController::class, 'serviceAreas']);
    Route::get('geo/check', [GeoController::class, 'check']);
    Route::get('vehicle-types', [GeoController::class, 'vehicleTypes']);
    Route::get('vehicle-features', [GeoController::class, 'vehicleFeatures']);
    Route::get('fares', [FareController::class, 'index']);
    Route::post('fares/estimate', [FareController::class, 'estimate']);

    // Own limit, so checking a number doesn't eat into the per-phone code/verify budget.
    Route::post('auth/otp/lookup', [OtpController::class, 'lookup'])->middleware('throttle:otp-lookup');
    Route::prefix('auth/otp')->middleware('throttle:otp')->group(function () {
        Route::post('request', [OtpController::class, 'request']);
        Route::post('verify', [OtpController::class, 'verify']);
    });

    // Browser- and gateway-facing payment endpoints (no bearer token).
    Route::match(['get', 'post'], 'payments/{payment}/return', [PaymentController::class, 'return'])->name('payments.return');
    Route::post('webhooks/payments/{gateway}', [PaymentController::class, 'webhook'])->name('payments.webhook');
    Route::match(['get', 'post'], 'payment-methods/{paymentMethod}/return', [PaymentMethodController::class, 'return'])->name('payment-methods.return');
    Route::middleware('signed')->group(function () {
        Route::get('payments/test/{payment}', [PaymentController::class, 'testCheckout'])->name('payments.test.checkout');
        Route::post('payments/test/{payment}/approve', [PaymentController::class, 'testDecide'])->name('payments.test.approve');
        Route::post('payments/test/{payment}/decline', [PaymentController::class, 'testDecide'])->name('payments.test.decline');
        Route::get('payment-methods/test/{paymentMethod}', [PaymentMethodController::class, 'testForm'])->name('payment-methods.test.form');
        Route::post('payment-methods/test/{paymentMethod}', [PaymentMethodController::class, 'testSubmit'])->name('payment-methods.test.submit');
    });

    // ---------------------------------------------------------------- authenticated
    Route::middleware(['auth:sanctum', 'last-seen'])->group(function () {
        Route::get('me', [SessionController::class, 'me']);
        Route::patch('me', [SessionController::class, 'update']);
        Route::post('me/avatar', [FileController::class, 'uploadAvatar']);
        Route::get('me/outstanding', [PaymentController::class, 'outstanding']);
        Route::get('me/payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('me/payment-methods', [PaymentMethodController::class, 'store']);
        Route::get('me/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'show']);
        Route::post('me/payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'makeDefault']);
        Route::delete('me/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
        Route::post('auth/logout', [SessionController::class, 'logout']);
        // Shop orders
        Route::post('shop/orders', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'place'])->middleware('throttle:20,1');
        Route::get('shop/orders', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'index']);
        Route::get('shop/orders/active', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'active']);
        Route::get('shop/orders/{order}', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'show']);
        Route::post('shop/orders/{order}/cancel', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'cancel']);
        Route::post('shop/orders/{order}/change', [\App\Http\Controllers\Api\V1\Shop\ShopController::class, 'respondToChange']);
        Route::post('devices', [SessionController::class, 'registerDevice']);
        Route::delete('devices', [SessionController::class, 'forgetDevice']);
        // Newer features (app pages, saved places, scheduled rides, tips, promos, safety, support, payouts).
        require __DIR__.'/api_features.php';

        Route::get('files/avatars/{user}', [FileController::class, 'avatar'])->name('files.avatar');
        Route::get('files/documents/{document}', [FileController::class, 'document'])->name('files.document');

        Route::get('payments/{payment}', [PaymentController::class, 'show']);

        // Address search (Google Places, proxied)
        Route::prefix('places')->middleware('throttle:places')->group(function () {
            Route::get('autocomplete', [PlaceController::class, 'autocomplete']);
            Route::get('reverse', [PlaceController::class, 'reverse']);
            Route::get('{placeId}', [PlaceController::class, 'show']);
        });

        // Rider
        Route::prefix('trips')->group(function () {
            Route::get('/', [RiderTripController::class, 'index']);
            Route::get('active', [RiderTripController::class, 'active']);
            Route::get('scheduled', [RiderTripController::class, 'scheduled']);
            Route::post('/', [RiderTripController::class, 'store'])->middleware('role:rider,admin');
            Route::get('{trip}', [RiderTripController::class, 'show']);
            Route::post('{trip}/cancel', [RiderTripController::class, 'cancel']);
            Route::post('{trip}/stops', [RiderTripController::class, 'changeStops']);
            Route::delete('{trip}/stops/pending', [RiderTripController::class, 'withdrawStopChange']);
            Route::post('{trip}/rate', [RiderTripController::class, 'rate']);
            Route::post('{trip}/pay', [PaymentController::class, 'pay']);
        });

        // Driver
        Route::prefix('driver')->middleware('role:driver')->group(function () {
            Route::get('profile', [DriverProfileController::class, 'show']);
            Route::patch('profile', [DriverProfileController::class, 'update']);

            Route::get('documents', [DriverDocumentController::class, 'index']);
            Route::post('documents', [DriverDocumentController::class, 'store']);
            Route::delete('documents/{document}', [DriverDocumentController::class, 'destroy']);

            Route::get('vehicles', [DriverProfileController::class, 'vehicles']);
            Route::post('vehicles', [DriverProfileController::class, 'storeVehicle']);
            Route::patch('vehicles/{vehicle}', [DriverProfileController::class, 'updateVehicle']);
            Route::post('vehicles/{vehicle}/select', [DriverProfileController::class, 'selectVehicle']);
            Route::get('vehicles/{vehicle}/documents', [DriverDocumentController::class, 'vehicleIndex']);
            Route::post('vehicles/{vehicle}/documents', [DriverDocumentController::class, 'vehicleStore']);

            Route::post('online', [DriverStatusController::class, 'online']);
            Route::post('offline', [DriverStatusController::class, 'offline']);
            Route::post('location', [DriverStatusController::class, 'location'])->middleware('throttle:location');
            Route::get('offers', [DriverStatusController::class, 'offers']);
            Route::get('earnings', [EarningsController::class, 'summary']);

            Route::get('trips', [DriverTripController::class, 'index']);
            Route::get('trips/active', [DriverTripController::class, 'active']);
            Route::get('trips/{trip}', [DriverTripController::class, 'show']);
            Route::post('trips/{trip}/accept', [DriverTripController::class, 'accept']);
            Route::post('trips/{trip}/decline', [DriverTripController::class, 'decline']);
            Route::post('trips/{trip}/stops/accept', [DriverTripController::class, 'acceptStops']);
            Route::post('trips/{trip}/stops/decline', [DriverTripController::class, 'declineStops']);
            Route::post('trips/{trip}/stops/{index}/reached', [DriverTripController::class, 'reachStop'])->whereNumber('index');
            Route::post('trips/{trip}/arrive', [DriverTripController::class, 'arrive']);
            Route::post('trips/{trip}/start', [DriverTripController::class, 'start']);
            Route::post('trips/{trip}/navigation', [DriverTripController::class, 'navigation']);
            Route::post('trips/{trip}/complete', [DriverTripController::class, 'complete']);
            Route::post('trips/{trip}/cancel', [DriverTripController::class, 'cancel']);
        });

        // Admin
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('stats', [DashboardController::class, 'stats']);
            Route::get('live', [DashboardController::class, 'live']);

            Route::get('users', [UserAdminController::class, 'index']);
            Route::patch('users/{user}', [UserAdminController::class, 'update']);

            Route::get('drivers', [DriverAdminController::class, 'index']);
            Route::get('drivers/{user}', [DriverAdminController::class, 'show']);
            Route::patch('drivers/{user}/status', [DriverAdminController::class, 'setStatus']);
            Route::patch('vehicles/{vehicle}/status', [DriverAdminController::class, 'setVehicleStatus']);

            Route::get('document-types', [DocumentAdminController::class, 'types']);
            Route::post('document-types', [DocumentAdminController::class, 'storeType']);
            Route::patch('document-types/{documentType}', [DocumentAdminController::class, 'updateType']);
            Route::get('documents', [DocumentAdminController::class, 'index']);
            Route::get('documents/{document}', [DocumentAdminController::class, 'show']);
            Route::post('documents/{document}/review', [DocumentAdminController::class, 'review']);

            Route::get('service-areas', [AreaAdminController::class, 'index']);
            Route::post('service-areas', [AreaAdminController::class, 'store']);
            Route::get('service-areas/{area}', [AreaAdminController::class, 'show']);
            Route::patch('service-areas/{area}', [AreaAdminController::class, 'update']);
            Route::delete('service-areas/{area}', [AreaAdminController::class, 'destroy']);
            Route::post('geo/check', [AreaAdminController::class, 'check']);

            Route::get('vehicle-types', [FareAdminController::class, 'vehicleTypes']);
            Route::put('vehicle-types/{key}', [FareAdminController::class, 'upsertVehicleType']);
            Route::get('service-areas/{area}/fares', [FareAdminController::class, 'configs']);
            Route::put('service-areas/{area}/fares/{vehicleType}', [FareAdminController::class, 'upsertConfig']);
            Route::get('fare-rules', [FareAdminController::class, 'rules']);
            Route::post('fare-rules', [FareAdminController::class, 'storeRule']);
            Route::patch('fare-rules/{rule}', [FareAdminController::class, 'updateRule']);
            Route::delete('fare-rules/{rule}', [FareAdminController::class, 'destroyRule']);
            Route::post('fares/simulate', [FareAdminController::class, 'simulate']);

            Route::get('plugins', [GatewayAdminController::class, 'plugins']);
            Route::get('gateways', [GatewayAdminController::class, 'index']);
            Route::post('gateways', [GatewayAdminController::class, 'store']);
            Route::patch('gateways/{gateway}', [GatewayAdminController::class, 'update']);
            Route::delete('gateways/{gateway}', [GatewayAdminController::class, 'destroy']);
            Route::post('gateways/{gateway}/test', [GatewayAdminController::class, 'test']);

            Route::get('payments', [PaymentAdminController::class, 'index']);
            Route::post('payments/{payment}/refund', [PaymentAdminController::class, 'refund']);
            Route::post('trips/{trip}/waive', [PaymentAdminController::class, 'waive']);

            Route::get('trips', [TripAdminController::class, 'index']);
            Route::get('trips/{trip}', [TripAdminController::class, 'show']);
            Route::post('trips/{trip}/cancel', [TripAdminController::class, 'cancel']);
        });
    });
});
