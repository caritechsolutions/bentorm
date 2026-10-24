<?php

namespace App\Plugins\Contracts;

use App\Support\PushMessage;

interface PushPlugin extends Plugin
{
    /**
     * Deliver to one device. Returns false when the token is no longer valid (app uninstalled / reinstalled),
     * so the caller can forget it. Throws on anything else (credentials, outage).
     */
    public function send(string $token, PushMessage $message): bool;
}
