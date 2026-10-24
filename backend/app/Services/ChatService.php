<?php

namespace App\Services;

use App\Events\UserSignal;
use App\Models\Trip;
use App\Models\TripMessage;
use App\Models\User;
use App\Support\Features;
use App\Support\PushMessage;

/** Rider ↔ driver messages during a trip (no phone numbers needed). Kept 30 days. */
class ChatService
{
    public function __construct(private readonly PushService $push) {}

    public function canChat(Trip $trip): bool
    {
        return Features::on('chat') && $trip->driver_id !== null && in_array($trip->status->value, ['accepted', 'arrived', 'in_progress'], true);
    }

    /** @return array<int, array<string, mixed>> messages after $afterId, oldest first; marks the other side's as read. */
    public function list(Trip $trip, User $viewer, int $afterId = 0): array
    {
        abort_unless($trip->involves($viewer), 404);
        TripMessage::query()->where('trip_id', $trip->id)->where('sender_id', '!=', $viewer->id)->whereNull('read_at')->update(['read_at' => now()]);

        return TripMessage::query()->where('trip_id', $trip->id)->where('id', '>', $afterId)->with('sender')->orderBy('id')->limit(200)->get()
            ->map(fn (TripMessage $m) => $m->toApi($viewer))->all();
    }

    public function send(Trip $trip, User $sender, string $body): TripMessage
    {
        Features::require('chat', 'Chat');
        abort_unless($trip->involves($sender), 404);
        abort_unless($this->canChat($trip), 409, 'Chat is open while a driver is on the way and during the trip.');
        $m = TripMessage::query()->create(['trip_id' => $trip->id, 'sender_id' => $sender->id, 'body' => mb_substr(trim($body), 0, 500)]);
        $to = $sender->id === $trip->driver_id ? $trip->rider_id : $trip->driver_id;
        event(new UserSignal($to, 'chat.message', ['trip_id' => $trip->public_id, 'id' => $m->id, 'body' => $m->body, 'sender' => $sender->firstName()]));
        $this->push->notify($to, new PushMessage($sender->firstName(), $m->body, ['type' => 'chat', 'trip_id' => $trip->public_id],
            collapseKey: 'chat-'.$trip->public_id, urgent: true));

        return $m;
    }
}
