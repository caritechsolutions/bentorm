<?php

return [
    'length' => 6,
    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
    'max_attempts' => 5,
    // Minimum seconds between two OTP requests for the same phone.
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN', 30),
    // When true the code is returned in the API response (local/testing only — never in production).
    'expose_code' => (bool) env('OTP_EXPOSE_CODE', false),
    // A fixed code accepted for any phone when set (local/testing only).
    'static_code' => env('OTP_STATIC_CODE'),
];
