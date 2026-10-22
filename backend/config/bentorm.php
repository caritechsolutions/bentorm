<?php

return [
    'admin_phone' => env('ADMIN_PHONE'),
    'admin_name' => env('ADMIN_NAME', 'Administrator'),
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
