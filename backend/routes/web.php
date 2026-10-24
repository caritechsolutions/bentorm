<?php

use App\Models\Document;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => match (request()->getHost()) {
    config('bentorm.admin_host') => redirect('/admin'),
    config('bentorm.store_host') => redirect('/store'),
    default => response()->json(['service' => 'bentorm-api', 'health' => '/api/v1/health']),
});

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
