<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

use App\Ledger\Domain\Exception\ClosedAccountPosting;
use App\Ledger\Domain\Exception\UnknownAccountPosting;

/**
 * Port for checking whether an account may be posted against. Implemented by an
 * adapter that bridges to the accounts context.
 */
interface AccountStatusReader
{
    /**
     * @throws ClosedAccountPosting  if the referenced account is closed
     * @throws UnknownAccountPosting if the referenced account does not exist
     */
    public function assertPostable(AccountRef $account): void;
}
