<?php

namespace App\Contracts;

interface SmsSender
{
    /** Send a plain-text SMS to an E.164 phone number. */
    public function send(string $phone, string $message): void;
}
