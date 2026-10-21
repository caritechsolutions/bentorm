<?php

return [
    // 'log' (development) or 'http' (generic HTTP gateway)
    'driver' => env('SMS_DRIVER', 'log'),
    'log_channel' => env('SMS_LOG_CHANNEL', 'single'),

    // Generic HTTP gateway — fill in for the chosen Barbados provider.
    'http' => [
        'endpoint' => env('SMS_HTTP_ENDPOINT'),
        'auth_header' => env('SMS_HTTP_AUTH_HEADER', 'Authorization'),
        'auth_value' => env('SMS_HTTP_AUTH_VALUE'),
        'to_field' => env('SMS_HTTP_TO_FIELD', 'to'),
        'message_field' => env('SMS_HTTP_MESSAGE_FIELD', 'message'),
        'json' => env('SMS_HTTP_JSON', true),
        'extra_fields' => [
            // e.g. 'from' => env('SMS_SENDER_ID'),
        ],
    ],

    'sender_id' => env('SMS_SENDER_ID', 'Bentorm'),
];
