<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Trip;
use App\Models\User;
use App\Support\PushMessage;

/** Support tickets from the apps (incl. lost items) and replies from the admin panel. */
class SupportService
{
    public function __construct(private readonly PushService $push) {}

    public function open(User $user, string $category, string $body, ?Trip $trip = null, ?Order $order = null, ?string $subject = null): SupportTicket
    {
        if ($trip) {
            abort_unless($trip->involves($user), 404);
        }
        if ($order) {
            abort_unless($order->customer_id === $user->id, 404);
        }
        $subject = $subject ?: (SupportTicket::CATEGORIES[$category] ?? 'Help').($trip ? ' · '.$trip->requested_at?->format('j M') : '').($order ? ' · order '.$order->number() : '');
        $t = SupportTicket::query()->create(['user_id' => $user->id, 'trip_id' => $trip?->id, 'order_id' => $order?->id ?? $trip?->order_id,
            'category' => $category, 'subject' => mb_substr($subject, 0, 255), 'last_message_at' => now()]);
        $t->messages()->create(['user_id' => $user->id, 'body' => mb_substr(trim($body), 0, 5000)]);

        // Lost item on a recent ride: let the driver know to look (support will put them in touch).
        if ($category === 'lost_item' && $trip?->driver_id && $trip->rider_id === $user->id) {
            $this->push->notify($trip->driver_id, new PushMessage('Lost item report',
                $user->firstName().' may have left something in your car ('.$trip->requested_at?->format('j M, g:i a').'). Please check — Ride Coop support will contact you.',
                ['type' => 'support', 'trip_id' => $trip->public_id]));
        }

        return $t;
    }

    public function reply(SupportTicket $t, User $from, string $body, bool $staff): SupportMessage
    {
        abort_unless($staff || $t->user_id === $from->id, 404);
        $m = $t->messages()->create(['user_id' => $from->id, 'is_staff' => $staff, 'body' => mb_substr(trim($body), 0, 5000)]);
        $t->forceFill(['status' => $staff ? 'answered' : 'open', 'last_message_at' => now()])->save();
        if ($staff) {
            $this->push->notify($t->user_id, new PushMessage('Ride Coop support replied', mb_strimwidth($m->body, 0, 120, '…'),
                ['type' => 'support', 'ticket_id' => (string) $t->id, 'page' => 'support/tickets/'.$t->id]));
        }

        return $m;
    }

    public static function audienceOf(User $u): string
    {
        return $u->isDriver() ? 'driver' : 'rider';
    }
}
