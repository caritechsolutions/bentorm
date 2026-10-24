<?php

use App\Http\Controllers\AppPages\EnterController;
use Illuminate\Support\Facades\Route;

/*
| Web pages shown inside the rider and driver apps (prefix /app, web session). The apps open
| POST /api/v1/app-links {page} → /app/enter/{token} → /app/{page}. Change these pages on the server
| without an app update.
*/
Route::get('enter/{token}', EnterController::class)->middleware('throttle:30,1');
Route::get('signed-out', fn () => view('app.message', [
    'title' => 'Open this from the app',
    'body' => 'This page opens from inside the Ride Coop app. Go back to the app and tap the menu item again.',
]));

Route::middleware('auth')->group(function () {
    Route::get('about', fn () => view('app.about', ['user' => auth()->user()]));
});
