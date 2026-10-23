<?php

return [
    // Name riders and drivers see (SMS codes, emails, payment pages).
    'brand_name' => env('BRAND_NAME', 'Ride Coop'),

    // Extra stops a rider may add between pickup and drop-off.
    'max_stops' => (int) env('MAX_STOPS', 2),
    // Wrong ride-PIN entries before the driver must wait pin_lock_minutes.
    'pin_max_attempts' => 5,
    'pin_lock_minutes' => 2,

    'admin_phone' => env('ADMIN_PHONE'),
    'admin_name' => env('ADMIN_NAME', 'Administrator'),
    // Host serving the web admin panel (requests to "/" there redirect to /admin).
    'admin_host' => env('ADMIN_HOST'),
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
