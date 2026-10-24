<?php

use App\Models\OtpCode;
use Illuminate\Support\Facades\Schedule;

// Housekeeping
Schedule::call(fn () => OtpCode::query()->where('expires_at', '<', now()->subDay())->delete())->daily()->name('otp:prune');
Schedule::command('sanctum:prune-expired --hours=720')->daily();
Schedule::command('trips:expire-stale')->everyMinute();
Schedule::command('documents:expire')->hourly();
// Shop: orders a store never answered are released after OrderService::ACCEPT_MINUTES.
Schedule::call(fn () => app(App\Services\OrderService::class)->expireUnanswered())->everyMinute()->name('orders:expire')->withoutOverlapping();
// Shop deliveries: send drivers when orders are nearly ready, retry jobs nobody took, alert after a while.
Schedule::call(fn () => app(App\Services\OrderService::class)->dispatchDue())->everyMinute()->name('orders:dispatch')->withoutOverlapping();
// Rides booked for later: hold the card and start finding a driver shortly before pickup.
Schedule::call(fn () => app(App\Services\TripService::class)->startDueScheduled())->everyMinute()->name('trips:scheduled')->withoutOverlapping();
