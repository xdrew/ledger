<?php

declare(strict_types=1);

namespace App\Ledger\Domain\Exception;

use App\Ledger\Domain\AccountRef;

/**
 * Raised when a journal entry references a closed account.
 */
final class ClosedAccountPosting extends \RuntimeException
{
    public static function forAccount(AccountRef $account): self
    {
        return new self(\sprintf('Cannot post to closed account "%s".', $account->value));
    }
}
