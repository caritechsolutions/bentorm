<?php

use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DriverAdminController;
use App\Http\Controllers\Api\V1\Admin\FareAdminController;
use App\Http\Controllers\Api\V1\Admin\TripAdminController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Driver\ProfileController as DriverProfileController;
use App\Http\Controllers\Api\V1\Driver\StatusController as DriverStatusController;
use App\Http\Controllers\Api\V1\Driver\TripController as DriverTripController;
use App\Http\Controllers\Api\V1\FareController;
use App\Http\Controllers\Api\V1\Rider\TripController as RiderTripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---------------------------------------------------------------- public
    Route::get('health', fn () => ['ok' => true, 'time' => now()->toIso8601String()]);
    Route::get('fares', [FareController::class, 'index']);
    Route::post('fares/estimate', [FareController::class, 'estimate']);

    Route::prefix('auth/otp')->middleware('throttle:otp')->group(function () {
        Route::post('request', [OtpController::class, 'request']);
        Route::post('verify', [OtpController::class, 'verify']);
    });

    // ---------------------------------------------------------------- authenticated
    Route::middleware(['auth:sanctum', 'last-seen'])->group(function () {
        Route::get('me', [SessionController::class, 'me']);
        Route::patch('me', [SessionController::class, 'update']);
        Route::post('auth/logout', [SessionController::class, 'logout']);
        Route::post('devices', [SessionController::class, 'registerDevice']);

        // Rider
        Route::prefix('trips')->group(function () {
            Route::get('/', [RiderTripController::class, 'index']);
            Route::get('active', [RiderTripController::class, 'active']);
            Route::post('/', [RiderTripController::class, 'store'])->middleware('role:rider,admin');
            Route::get('{trip}', [RiderTripController::class, 'show']);
            Route::post('{trip}/cancel', [RiderTripController::class, 'cancel']);
            Route::post('{trip}/rate', [RiderTripController::class, 'rate']);
        });

        // Driver
        Route::prefix('driver')->middleware('role:driver')->group(function () {
            Route::get('profile', [DriverProfileController::class, 'show']);
            Route::patch('profile', [DriverProfileController::class, 'update']);
            Route::get('vehicles', [DriverProfileController::class, 'vehicles']);
            Route::post('vehicles', [DriverProfileController::class, 'storeVehicle']);
            Route::post('vehicles/{vehicle}/select', [DriverProfileController::class, 'selectVehicle']);

            Route::post('online', [DriverStatusController::class, 'online']);
            Route::post('offline', [DriverStatusController::class, 'offline']);
            Route::post('location', [DriverStatusController::class, 'location'])->middleware('throttle:location');
            Route::get('offers', [DriverStatusController::class, 'offers']);

            Route::get('trips', [DriverTripController::class, 'index']);
            Route::get('trips/active', [DriverTripController::class, 'active']);
            Route::post('trips/{trip}/accept', [DriverTripController::class, 'accept']);
            Route::post('trips/{trip}/decline', [DriverTripController::class, 'decline']);
            Route::post('trips/{trip}/arrive', [DriverTripController::class, 'arrive']);
            Route::post('trips/{trip}/start', [DriverTripController::class, 'start']);
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

            Route::get('trips', [TripAdminController::class, 'index']);
            Route::get('trips/{trip}', [TripAdminController::class, 'show']);
            Route::post('trips/{trip}/cancel', [TripAdminController::class, 'cancel']);

            Route::get('fares', [FareAdminController::class, 'index']);
            Route::put('fares/{vehicleType}', [FareAdminController::class, 'upsert']);
        });
    });
});
