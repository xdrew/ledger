<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Transfers\Domain\TransferId;

/**
 * Input to reverse a completed transfer with a new compensating transfer.
 */
final readonly class ReverseTransfer
{
    public function __construct(
        public TransferId $originalTransferId,
        public TransferId $newTransferId,
    ) {}
}
