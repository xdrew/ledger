<?php

declare(strict_types=1);

namespace App\Idempotency;

/**
 * Configurable retention for completed idempotency keys.
 */
final readonly class Ttl
{
    private function __construct(public int $seconds) {}

    public static function ofSeconds(int $seconds): self
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('TTL must be a positive number of seconds.');
        }

        return new self($seconds);
    }

    public function expiresFrom(\DateTimeImmutable $from): \DateTimeImmutable
    {
        return $from->add(new \DateInterval('PT' . $this->seconds . 'S'));
    }
}
