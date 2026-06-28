<?php

declare(strict_types=1);

namespace App\SharedKernel\Clock;

/**
 * Production clock — returns the current wall-clock time in UTC. This is the
 * one place the system reads the real clock; everything else depends on {@see Clock}.
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
