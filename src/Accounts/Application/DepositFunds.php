<?php

declare(strict_types=1);

namespace App\Accounts\Application;

use App\Accounts\Domain\AccountId;
use App\SharedKernel\Money\Money;

/**
 * Command: deposit funds into an account.
 */
final class DepositFunds
{
    public function __construct(
        public readonly AccountId $accountId,
        public readonly Money $amount,
    ) {}
}
