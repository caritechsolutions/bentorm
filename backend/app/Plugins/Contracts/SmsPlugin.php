<?php

namespace App\Plugins\Contracts;

interface SmsPlugin extends Plugin
{
    /** @param string $to E.164 phone number */
    public function send(string $to, string $message): void;
}
