<?php

declare(strict_types=1);

namespace App\Ledger\Domain\Exception;

use App\Ledger\Domain\AccountRef;

/**
 * Posting against an account that does not exist is refused before the journal
 * entry is created. Allowing it would let a transfer debit its source and then
 * fail to credit anyone — money destroyed (found live by the showcase demo).
 */
final class UnknownAccountPosting extends \DomainException
{
    public static function forAccount(AccountRef $account): self
    {
        return new self(\sprintf('Account "%s" does not exist; refusing to post against it.', $account->value));
    }
}
