<?php

namespace App\Services;

use App\Models\SafetyAlert;
use App\Models\Trip;
use App\Models\User;
use App\Support\Features;
use App\Support\PushMessage;
use Illuminate\Support\Str;

/** Share-my-trip links and the SOS button. */
class SafetyService
{
    public function __construct(private readonly PushService $push) {}

    /** A public link that shows this trip live until shortly after it ends. Rider or driver may share. */
    public function shareLink(Trip $trip, User $user): string
    {
        Features::require('share_trip', 'Sharing a trip');
        abort_unless($trip->involves($user), 404);
        abort_unless($trip->isActive() || $trip->status->value === 'scheduled', 409, 'Only a trip in progress can be shared.');
        if (! $trip->share_token) {
            $trip->forceFill(['share_token' => Str::random(32)])->saveQuietly();
        }

        return url('/t/'.$trip->share_token);
    }

    /** Is this shared link still live? (While the trip runs, and an hour after it ends.) */
    public function sharedTrip(string $token): ?Trip
    {
        $t = Trip::query()->where('share_token', $token)->first();
        if (! $t) {
            return null;
        }
        $ended = $t->completed_at ?? $t->cancelled_at;

        return ($t->isActive() || ! $ended || $ended->gt(now()->subHour())) ? $t : null;
    }

    /** SOS: record it, alert every admin (push + SMS) with where and a live link. Returns the alert. */
    public function sos(User $user, ?Trip $trip, ?float $lat, ?float $lng): SafetyAlert
    {
        Features::require('sos', 'SOS');
        if ($trip) {
            abort_unless($trip->involves($user), 404);
        }
        $role = $trip ? ($trip->driver_id === $user->id ? 'driver' : ($trip->order_id ? 'customer' : 'rider')) : ($user->isDriver() ? 'driver' : 'rider');
        $alert = SafetyAlert::query()->create(['trip_id' => $trip?->id, 'order_id' => $trip?->order_id, 'user_id' => $user->id, 'role' => $role, 'lat' => $lat, 'lng' => $lng]);
        $link = $trip ? $this->shareLinkQuiet($trip) : null;
        $where = $lat !== null ? sprintf(' at %.5f,%.5f (https://maps.google.com/?q=%.6f,%.6f)', $lat, $lng, $lat, $lng) : '';
        $text = "SOS from {$role} {$user->name} ({$user->phone})".($trip ? " on trip {$trip->public_id}" : '').$where.($link ? " Live: {$link}" : '');
        foreach (User::query()->where('role', 'admin')->where('is_active', true)->get() as $admin) {
            $this->push->notify($admin->id, new PushMessage('SOS — '.$user->name, $text, ['type' => 'sos', 'alert_id' => $alert->id], channel: 'ride_offers', urgent: true));
            try {
                app(\App\Contracts\SmsSender::class)->send($admin->phone, 'Ride Coop '.$text);
            } catch (\Throwable) {
                // Push and the admin panel still show it.
            }
        }

        return $alert;
    }

    private function shareLinkQuiet(Trip $trip): ?string
    {
        if (! $trip->share_token) {
            $trip->forceFill(['share_token' => Str::random(32)])->saveQuietly();
        }

        return url('/t/'.$trip->share_token);
    }

    /** @return array<int, array{name: string, number: string}> */
    public static function emergencyNumbers(): array
    {
        return config('bentorm.emergency_numbers');
    }
}
