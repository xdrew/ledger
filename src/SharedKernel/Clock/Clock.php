<?php

declare(strict_types=1);

namespace App\SharedKernel\Clock;

/**
 * Time source injected into the domain so aggregates and sagas never call
 * `new DateTimeImmutable()` directly, keeping them deterministic and testable.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
