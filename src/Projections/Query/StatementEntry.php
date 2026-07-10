<?php

declare(strict_types=1);

namespace App\Projections\Query;

use App\SharedKernel\Money\Money;

/**
 * Read-model view of a single account statement entry.
 */
final readonly class StatementEntry
{
    public function __construct(
        public string $accountId,
        public int $globalPosition,
        public string $entryType,
        public Money $amount,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
