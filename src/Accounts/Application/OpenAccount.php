<?php

declare(strict_types=1);

namespace App\Accounts\Application;

use App\Accounts\Domain\AccountId;
use App\SharedKernel\Money\Currency;

/**
 * Command: open a new account in a currency.
 */
final class OpenAccount
{
    public function __construct(
        public readonly AccountId $accountId,
        public readonly Currency $currency,
    ) {}
}
