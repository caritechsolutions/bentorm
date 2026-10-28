<?php

return [
    // Name riders and drivers see (SMS codes, emails, payment pages).
    'brand_name' => env('BRAND_NAME', 'Ride Coop'),

    // Shown on the SOS screen (Barbados: Police 211, Ambulance 511, Fire 311).
    'emergency_numbers' => [
        ['name' => 'Police', 'number' => env('EMERGENCY_POLICE', '211')],
        ['name' => 'Ambulance', 'number' => env('EMERGENCY_AMBULANCE', '511')],
        ['name' => 'Fire', 'number' => env('EMERGENCY_FIRE', '311')],
    ],

    // In-app calls (WebRTC). STUN finds a direct path; TURN relays when networks block it. TURN_SECRET is coturn's
    // static-auth-secret: the apps get short-lived credentials (scripts/install-turn.sh sets both).
    'calls' => [
        'stun' => array_filter(explode(',', (string) env('STUN_URLS', 'stun:stun.l.google.com:19302'))),
        'turn' => array_filter(explode(',', (string) env('TURN_URLS', ''))),
        'turn_secret' => env('TURN_SECRET'),
        'turn_ttl' => (int) env('TURN_TTL', 3600),
    ],

    // Extra stops a rider may add between pickup and drop-off.
    'max_stops' => (int) env('MAX_STOPS', 2),
    // Wrong ride-PIN entries before the driver must wait pin_lock_minutes.
    'pin_max_attempts' => 5,
    'pin_lock_minutes' => 2,

    'admin_phone' => env('ADMIN_PHONE'),
    'admin_name' => env('ADMIN_NAME', 'Administrator'),
    // Host serving the web admin panel (requests to "/" there redirect to /admin).
    'admin_host' => env('ADMIN_HOST'),
    // Optional friendly hostname for the store portal (/store); NPM points it at the same :8082 as the admin panel.
    'store_host' => env('STORE_HOST'),
    // Optional hostname for the fleet portal (/fleet), e.g. fleet.ridecoop.net → the same :8082.
    'fleet_host' => env('FLEET_HOST'),
    // Help, support and statement pages opened from the apps (e.g. support.ridecoop.net, proxied to the API port).
    // Unset: the API's own address is used.
    'support_host' => env('SUPPORT_HOST'),
    // Deep-link scheme the mobile apps register, used to bounce back from payment pages.
    'app_scheme' => env('APP_DEEPLINK_SCHEME', 'ridecoop'),

    // Vehicle features riders can request and drivers declare (checked by admin at vehicle approval).
    'vehicle_features' => [
        'wheelchair_accessible' => 'Wheelchair accessible',
        'child_seat' => 'Child seat',
        'pet_friendly' => 'Pet friendly',
        'extra_luggage' => 'Extra luggage space',
    ],
];
