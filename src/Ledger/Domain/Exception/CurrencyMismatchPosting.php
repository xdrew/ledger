<?php

declare(strict_types=1);

namespace App\Ledger\Domain\Exception;

use App\Ledger\Domain\AccountRef;
use App\SharedKernel\Money\Currency;

/**
 * Raised when a journal entry leg's currency does not match the denomination of
 * the account it posts to.
 */
final class CurrencyMismatchPosting extends \RuntimeException
{
    public static function forAccount(AccountRef $account, Currency $accountCurrency, Currency $legCurrency): self
    {
        return new self(\sprintf(
            'Cannot post %s to account "%s" denominated in %s.',
            $legCurrency->code,
            $account->value,
            $accountCurrency->code,
        ));
    }
}
