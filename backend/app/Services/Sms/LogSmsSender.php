<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/** Development sender — writes the message to the log instead of sending it. */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::channel(config('sms.log_channel', 'single'))->info("SMS to {$phone}: {$message}");
    }
}
