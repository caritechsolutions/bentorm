<?php

return [
    'admin_phone' => env('ADMIN_PHONE'),
    'admin_name' => env('ADMIN_NAME', 'Administrator'),
    // Deep-link scheme the mobile apps register, used to bounce back from payment pages.
    'app_scheme' => env('APP_DEEPLINK_SCHEME', 'ridecoop'),
];
