<?php

namespace App\Plugins;

final class CardSetupResult
{
    public const ACTIVE = 'active';
    public const REDIRECT = 'redirect';
    public const PENDING = 'pending';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $token = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $setupRef = null,
        public readonly ?string $brand = null,
        public readonly ?string $last4 = null,
        public readonly ?int $expMonth = null,
        public readonly ?int $expYear = null,
        public readonly ?string $holderName = null,
        public readonly ?string $message = null,
    ) {}

    public static function redirect(string $url, ?string $ref = null): self
    {
        return new self(self::REDIRECT, redirectUrl: $url, setupRef: $ref);
    }

    public static function active(string $token, ?string $brand, ?string $last4, ?int $expMonth, ?int $expYear, ?string $holder = null): self
    {
        return new self(self::ACTIVE, $token, null, null, $brand, $last4, $expMonth, $expYear, $holder);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, message: $message);
    }
}
