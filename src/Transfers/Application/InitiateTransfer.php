<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Accounts\Domain\AccountId;
use App\SharedKernel\Money\Money;
use App\Transfers\Domain\TransferId;

/**
 * Input to start a transfer.
 */
final readonly class InitiateTransfer
{
    public function __construct(
        public TransferId $transferId,
        public AccountId $sourceAccountId,
        public AccountId $destinationAccountId,
        public Money $amount,
        public ?TransferId $reversalOf = null,
    ) {}
}
