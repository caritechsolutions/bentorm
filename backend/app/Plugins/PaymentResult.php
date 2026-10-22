<?php

namespace App\Plugins;

final class PaymentResult
{
    public const AUTHORIZED = 'authorized';
    public const CAPTURED = 'captured';
    public const VOIDED = 'voided';
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
        public readonly ?float $amount = null,
    ) {}

    public static function authorized(float $amount, ?string $ref = null, array $raw = []): self
    {
        return new self(self::AUTHORIZED, $ref, null, null, $raw, $amount);
    }

    public static function captured(?string $ref = null, array $raw = [], ?float $amount = null): self
    {
        return new self(self::CAPTURED, $ref, null, null, $raw, $amount);
    }

    public static function voided(?string $ref = null, array $raw = []): self
    {
        return new self(self::VOIDED, $ref, null, null, $raw);
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

    public static function refunded(?string $ref = null, ?float $amount = null): self
    {
        return new self(self::REFUNDED, $ref, null, null, [], $amount);
    }
}
