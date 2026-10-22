<?php

namespace App\Plugins\Contracts;

interface EmailPlugin extends Plugin
{
    public function send(string $to, string $subject, string $html, ?string $text = null): void;
}
