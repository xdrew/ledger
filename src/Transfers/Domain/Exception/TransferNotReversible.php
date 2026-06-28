<?php

declare(strict_types=1);

namespace App\Transfers\Domain\Exception;

use App\Transfers\Domain\TransferId;
use App\Transfers\Domain\TransferStatus;

final class TransferNotReversible extends \RuntimeException
{
    public static function notCompleted(TransferId $id, TransferStatus $status): self
    {
        return new self(\sprintf('Transfer "%s" cannot be reversed from status %s; only completed transfers are reversible.', $id->toString(), $status->value));
    }
}
