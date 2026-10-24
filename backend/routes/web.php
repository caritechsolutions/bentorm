<?php

use App\Models\Document;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => match (request()->getHost()) {
    config('bentorm.admin_host') => redirect('/admin'),
    config('bentorm.store_host') => redirect('/store'),
    config('bentorm.support_host') => redirect('/app/help'),
    default => response()->json(['service' => 'bentorm-api', 'health' => '/api/v1/health']),
});

// Share my trip: a public live page (no login) while the trip runs and an hour after.
Route::get('/t/{token}', function (string $token, App\Services\SafetyService $safety) {
    $trip = $safety->sharedTrip($token);
    abort_unless($trip, 404);

    return view('share.trip', ['token' => $token, 'trip' => $trip]);
})->where('token', '[A-Za-z0-9]+')->middleware('throttle:60,1,get-t-token');
Route::get('/t/{token}.json', function (string $token, App\Services\SafetyService $safety, App\Services\DriverLocationService $locations) {
    $t = $safety->sharedTrip($token);
    abort_unless($t, 404);
    $v = $t->vehicle;

    return response()->json([
        'status' => $t->status->value,
        'kind' => $t->order_id ? 'delivery' : 'ride',
        'rider' => $t->rider?->firstName(),
        'driver' => $t->driver?->firstName(),
        'vehicle' => $v ? trim("{$v->colour} {$v->make} {$v->model}").' · '.$v->plate : null,
        'pickup' => ['lat' => (float) $t->pickup_lat, 'lng' => (float) $t->pickup_lng, 'address' => $t->pickup_address],
        'dropoff' => ['lat' => (float) $t->dropoff_lat, 'lng' => (float) $t->dropoff_lng, 'address' => $t->dropoff_address],
        'driver_position' => $t->isActive() && $t->driver_id && ($p = $locations->position($t->driver_id))
            ? ['lat' => $p['lat'], 'lng' => $p['lng'], 'heading' => $p['heading']] : null,
    ])->header('Cache-Control', 'no-store');
})->where('token', '[A-Za-z0-9]+')->middleware('throttle:120,1,get-t-token-json');

// Web pages inside the apps (help, support, statements…): routes/app.php
Route::prefix('app')->group(base_path('routes/app.php'));

// Private document files for the admin panel (session auth; the apps use /api/v1/files/documents/{id}).
Route::middleware(['web', 'auth'])->get('/admin-files/documents/{document}', function (Document $document) {
    $user = auth()->user();
    // Admins see every document; store staff see their own store's.
    $own = $document->documentable instanceof App\Models\Store && $user?->canAccessTenant($document->documentable);
    abort_unless($user?->isAdmin() || $own, 403);
    abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

    return Storage::disk($document->disk)->response($document->path, $document->original_name, [
        'Content-Type' => $document->mime ?: 'application/octet-stream',
        'Cache-Control' => 'private, max-age=600',
    ]);
})->name('admin.document.file');

// Store portal: which orders are waiting for this store (polled by the new-order alert; members only).
Route::middleware(['web', 'auth'])->get('/store-alerts/{store:slug}', function (App\Models\Store $store) {
    abort_unless(auth()->user()?->canAccessTenant($store), 403);

    return response()->json(['placed' => $store->orders()->where('status', 'placed')->orderBy('id')->pluck('public_id')]);
})->name('store.order-alerts');

// Live map data for the admin panel (session auth).
Route::middleware(['web', 'auth'])->get('/admin-live.json', function (App\Services\DriverLocationService $locations) {
    abort_unless(auth()->user()?->isAdmin(), 403);
    $live = $locations->allLive();
    $users = App\Models\User::query()->whereIn('id', array_column($live, 'driver_id'))->with('driverProfile.currentVehicle')->get()->keyBy('id');
    $drivers = [];
    foreach ($live as $d) {
        $u = $users->get($d['driver_id']);
        if (! $u) {
            continue;
        }
        $v = $u->driverProfile?->currentVehicle;
        $drivers[] = $d + ['name' => $u->name, 'phone' => $u->phone, 'vehicle' => $v ? ['plate' => $v->plate, 'make' => $v->make, 'model' => $v->model] : null];
    }
    $trips = App\Models\Trip::query()
        ->whereIn('status', array_map(fn ($s) => $s->value, App\Enums\TripStatus::active()))
        ->with(['rider', 'driver'])->latest('id')->limit(200)->get()
        ->map(fn ($t) => ['id' => $t->public_id, 'status' => $t->status->value, 'rider' => $t->rider?->name, 'driver' => $t->driver?->name,
            'pickup' => ['lat' => $t->pickup_lat, 'lng' => $t->pickup_lng], 'dropoff' => ['lat' => $t->dropoff_lat, 'lng' => $t->dropoff_lng]]);

    return response()->json(['drivers' => $drivers, 'trips' => $trips]);
})->name('admin.live');
