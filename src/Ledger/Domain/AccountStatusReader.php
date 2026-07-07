<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

use App\Ledger\Domain\Exception\ClosedAccountPosting;
use App\Ledger\Domain\Exception\CurrencyMismatchPosting;
use App\Ledger\Domain\Exception\FrozenAccountPosting;
use App\Ledger\Domain\Exception\UnknownAccountPosting;
use App\SharedKernel\Money\Currency;

/**
 * Port for checking whether an account may be posted against in a given
 * currency. Implemented by an adapter that bridges to the accounts context.
 */
interface AccountStatusReader
{
    /**
     * @throws ClosedAccountPosting     if the referenced account is closed
     * @throws FrozenAccountPosting     if the referenced account is frozen
     * @throws UnknownAccountPosting    if the referenced account does not exist
     * @throws CurrencyMismatchPosting  if the account is not denominated in $currency
     */
    public function assertPostable(AccountRef $account, Currency $currency): void;
}
