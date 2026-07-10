<?php

declare(strict_types=1);

namespace App\Accounts\Application;

use App\Accounts\Domain\AccountId;
use App\SharedKernel\Money\Currency;

/**
 * Command: open a new account in a currency.
 */
final readonly class OpenAccount
{
    public function __construct(
        public AccountId $accountId,
        public Currency $currency,
    ) {}
}
