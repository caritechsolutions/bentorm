<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Generic HTTP gateway sender. Configure endpoint, auth header and field names in config/sms.php
 * so any local SMS provider with a simple POST API can be plugged in without code changes.
 */
class HttpSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        $cfg = config('sms.http');

        $payload = array_merge($cfg['extra_fields'] ?? [], [
            $cfg['to_field'] => $phone,
            $cfg['message_field'] => $message,
        ]);

        $request = Http::timeout(10);
        if (! empty($cfg['auth_header']) && ! empty($cfg['auth_value'])) {
            $request = $request->withHeaders([$cfg['auth_header'] => $cfg['auth_value']]);
        }

        $response = ($cfg['json'] ?? true) ? $request->asJson()->post($cfg['endpoint'], $payload)
                                           : $request->asForm()->post($cfg['endpoint'], $payload);

        if (! $response->successful()) {
            throw new RuntimeException('SMS gateway error: '.$response->status().' '.$response->body());
        }
    }
}
