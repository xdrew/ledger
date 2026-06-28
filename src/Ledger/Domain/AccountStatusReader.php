<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

use App\Ledger\Domain\Exception\ClosedAccountPosting;

/**
 * Port for checking whether an account may be posted against. Implemented by an
 * adapter that bridges to the accounts context.
 */
interface AccountStatusReader
{
    /**
     * @throws ClosedAccountPosting if the referenced account is closed
     */
    public function assertPostable(AccountRef $account): void;
}
