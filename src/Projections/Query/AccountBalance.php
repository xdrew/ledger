<?php

declare(strict_types=1);

namespace App\Projections\Query;

use App\SharedKernel\Money\Money;

/**
 * Read-model view of an account's balances.
 */
final class AccountBalance
{
    public function __construct(
        public readonly string $accountId,
        public readonly Money $available,
        public readonly Money $reserved,
        public readonly Money $total,
        public readonly int $version,
    ) {}
}
