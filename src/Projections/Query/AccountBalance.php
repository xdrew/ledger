<?php

declare(strict_types=1);

namespace App\Projections\Query;

use App\SharedKernel\Money\Money;

/**
 * Read-model view of an account's balances.
 */
final readonly class AccountBalance
{
    public function __construct(
        public string $accountId,
        public Money $available,
        public Money $reserved,
        public Money $total,
        public int $version,
    ) {}
}
