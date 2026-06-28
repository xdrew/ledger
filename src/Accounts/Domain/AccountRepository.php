<?php

declare(strict_types=1);

namespace App\Accounts\Domain;

use App\Accounts\Domain\Exception\AccountNotFound;
use App\EventStore\EventMetadata;

interface AccountRepository
{
    /**
     * @throws AccountNotFound when no stream exists for the id
     */
    public function load(AccountId $id): Account;

    public function save(Account $account, ?EventMetadata $metadata = null): void;
}
