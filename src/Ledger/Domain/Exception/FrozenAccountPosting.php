<?php

declare(strict_types=1);

namespace App\Ledger\Domain\Exception;

use App\Ledger\Domain\AccountRef;

/**
 * Raised when a journal entry references a frozen account.
 */
final class FrozenAccountPosting extends \RuntimeException
{
    public static function forAccount(AccountRef $account): self
    {
        return new self(\sprintf('Cannot post to frozen account "%s".', $account->value));
    }
}
