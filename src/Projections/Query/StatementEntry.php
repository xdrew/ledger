<?php

declare(strict_types=1);

namespace App\Projections\Query;

use App\SharedKernel\Money\Money;

/**
 * Read-model view of a single account statement entry.
 */
final class StatementEntry
{
    public function __construct(
        public readonly string $accountId,
        public readonly int $globalPosition,
        public readonly string $entryType,
        public readonly Money $amount,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
