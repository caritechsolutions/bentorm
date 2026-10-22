<?php

namespace App\Plugins;

final class PaymentResult
{
    public const CAPTURED = 'captured';
    public const PENDING = 'pending';
    public const REDIRECT = 'redirect';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';

    public function __construct(
        public readonly string $status,
        public readonly ?string $gatewayRef = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    public static function captured(?string $ref = null, array $raw = []): self
    {
        return new self(self::CAPTURED, $ref, null, null, $raw);
    }

    public static function redirect(string $url, ?string $ref = null, array $raw = []): self
    {
        return new self(self::REDIRECT, $ref, $url, null, $raw);
    }

    public static function pending(?string $ref = null, array $raw = []): self
    {
        return new self(self::PENDING, $ref, null, null, $raw);
    }

    public static function failed(string $message, ?string $ref = null, array $raw = []): self
    {
        return new self(self::FAILED, $ref, null, $message, $raw);
    }
}
