<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Accounts\Domain\AccountId;
use App\SharedKernel\Money\Money;
use App\Transfers\Domain\TransferId;

/**
 * Input to start a transfer.
 */
final class InitiateTransfer
{
    public function __construct(
        public readonly TransferId $transferId,
        public readonly AccountId $sourceAccountId,
        public readonly AccountId $destinationAccountId,
        public readonly Money $amount,
        public readonly ?TransferId $reversalOf = null,
    ) {}
}
