<?php

return [
    // 'haversine' or 'osrm'
    'distance_provider' => env('DISTANCE_PROVIDER', 'haversine'),
    'osrm_url' => env('OSRM_URL', 'https://router.project-osrm.org'),

    // Haversine scaling: straight line × road_factor ≈ road distance.
    'road_factor' => (float) env('PRICING_ROAD_FACTOR', 1.3),
    'assumed_speed_kmh' => (float) env('PRICING_ASSUMED_SPEED_KMH', 30),

    'currency' => env('PRICING_CURRENCY', 'BBD'),
    'rounding' => 0.50, // round fares up to the nearest 50 cents
];
