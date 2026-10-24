<?php

namespace App\Support;

/** A push notification, provider-neutral. */
final class PushMessage
{
    /**
     * @param  array<string, string|int|float|bool|null>  $data  delivered to the app (all values sent as strings)
     * @param  string  $channel  Android notification channel the app creates: ride_offers (loud, rings) | ride_updates
     * @param  int|null  $ttlSeconds  drop it if it can't be delivered in time (an expired ride offer is useless)
     * @param  string|null  $collapseKey  a newer message with the same key replaces the older one on the phone
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly string $channel = 'ride_updates',
        public readonly ?int $ttlSeconds = null,
        public readonly ?string $collapseKey = null,
        public readonly bool $urgent = false,
    ) {}
}
