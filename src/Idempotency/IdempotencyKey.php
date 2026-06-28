<?php

declare(strict_types=1);

namespace App\Idempotency;

/**
 * Client-supplied idempotency key (the `Idempotency-Key` header value).
 */
final class IdempotencyKey
{
    private function __construct(public readonly string $value) {}

    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Idempotency key must be non-empty.');
        }

        return new self($value);
    }
}
