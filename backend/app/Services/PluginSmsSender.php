<?php

namespace App\Services;

use App\Contracts\SmsSender;
use App\Plugins\PluginManager;

/** Routes SMS through the active SMS gateway plugin (falls back to the log when none is configured). */
class PluginSmsSender implements SmsSender
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function send(string $phone, string $message): void
    {
        $this->plugins->sms()->send($phone, $message);
    }
}
