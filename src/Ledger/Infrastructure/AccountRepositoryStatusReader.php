<?php

declare(strict_types=1);

namespace App\Ledger\Infrastructure;

use App\Accounts\Domain\AccountId;
use App\Accounts\Domain\AccountRepository;
use App\Accounts\Domain\AccountStatus;
use App\Accounts\Domain\Exception\AccountNotFound;
use App\Ledger\Domain\AccountRef;
use App\Ledger\Domain\AccountStatusReader;
use App\Ledger\Domain\Exception\ClosedAccountPosting;

/**
 * Bridges the ledger's account-status port to the accounts context. Today it
 * loads the account aggregate; once `add-projections` exists this moves onto the
 * account-status read model to avoid an aggregate load on the posting path.
 */
final class AccountRepositoryStatusReader implements AccountStatusReader
{
    public function __construct(private readonly AccountRepository $accounts) {}

    public function assertPostable(AccountRef $account): void
    {
        try {
            $loaded = $this->accounts->load(AccountId::fromString($account->value));
        } catch (AccountNotFound) {
            // The ledger may reference accounts not modelled as aggregates here;
            // only a known-closed account is forbidden.
            return;
        }

        if ($loaded->status() === AccountStatus::Closed) {
            throw ClosedAccountPosting::forAccount($account);
        }
    }
}
