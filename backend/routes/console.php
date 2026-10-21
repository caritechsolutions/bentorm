<?php

use App\Models\OtpCode;
use Illuminate\Support\Facades\Schedule;

// Housekeeping
Schedule::call(fn () => OtpCode::query()->where('expires_at', '<', now()->subDay())->delete())->daily()->name('otp:prune');
Schedule::command('sanctum:prune-expired --hours=720')->daily();
Schedule::command('trips:expire-stale')->everyMinute();
