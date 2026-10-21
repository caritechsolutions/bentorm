<?php

return [
    // Search radius for nearby drivers, in metres.
    'search_radius_m' => (int) env('DISPATCH_RADIUS_M', 8000),
    // How many drivers are offered the trip simultaneously per wave.
    'offer_batch_size' => (int) env('DISPATCH_BATCH_SIZE', 3),
    // How long a driver has to accept an offer.
    'offer_ttl_seconds' => (int) env('DISPATCH_OFFER_TTL', 20),
    // Maximum waves before the trip is marked no_drivers.
    'max_waves' => (int) env('DISPATCH_MAX_WAVES', 4),
    // Driver heartbeat TTL — a driver with no location update within this window is treated as offline.
    'heartbeat_ttl_seconds' => (int) env('DISPATCH_HEARTBEAT_TTL', 90),
];
