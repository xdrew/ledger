<?php

declare(strict_types=1);

namespace App\Accounts\Application;

use App\Accounts\Domain\AccountId;
use App\SharedKernel\Money\Money;

/**
 * Command: deposit funds into an account.
 */
final readonly class DepositFunds
{
    public function __construct(
        public AccountId $accountId,
        public Money $amount,
    ) {}
}
