<?php

use App\Http\Controllers\AppPages\EnterController;
use Illuminate\Support\Facades\Route;

/*
| Web pages shown inside the rider and driver apps (prefix /app, web session). The apps open
| POST /api/v1/app-links {page} → /app/enter/{token} → /app/{page}. Change these pages on the server
| without an app update.
*/
Route::get('enter/{token}', EnterController::class)->middleware('throttle:30,1,get-enter-token');
Route::get('signed-out', fn () => view('app.message', [
    'title' => 'Open this from the app',
    'body' => 'This page opens from inside the Ride Coop app. Go back to the app and tap the menu item again.',
]));

// auth:web pins these pages to the web session the app link opened (never an API token).
Route::middleware('auth:web')->group(function () {
    Route::get('about', fn () => view('app.about', ['user' => auth()->user()]));
    // Driver statement and bank details for payouts
    Route::get('statement', [\App\Http\Controllers\AppPages\StatementController::class, 'show']);
    Route::get('statement/bank', [\App\Http\Controllers\AppPages\StatementController::class, 'bank']);
    Route::post('statement/bank', [\App\Http\Controllers\AppPages\StatementController::class, 'saveBank'])->middleware('throttle:10,1,post-statement-bank');
    // Help & support
    Route::get('help', [\App\Http\Controllers\AppPages\SupportPagesController::class, 'help']);
    Route::get('help/{slug}', [\App\Http\Controllers\AppPages\SupportPagesController::class, 'article']);
    Route::get('support/new', [\App\Http\Controllers\AppPages\SupportPagesController::class, 'create']);
    Route::post('support', [\App\Http\Controllers\AppPages\SupportPagesController::class, 'store'])->middleware('throttle:10,1,post-support');
    Route::get('support/tickets', [\App\Http\Controllers\AppPages\SupportPagesController::class, 'tickets']);
    Route::get('support/tickets/{ticket}', [\App\Http\Controllers\AppPages\SupportPagesController::class, 'ticket']);
    Route::post('support/tickets/{ticket}', [\App\Http\Controllers\AppPages\SupportPagesController::class, 'reply'])->middleware('throttle:20,1,post-support-tickets-ticket');
});
