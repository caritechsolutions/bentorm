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
