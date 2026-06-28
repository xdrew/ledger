<?php

declare(strict_types=1);

namespace App\Transfers\Domain\Exception;

use App\Transfers\Domain\TransferStatus;

final class InvalidTransferTransition extends \RuntimeException
{
    public static function expected(TransferStatus $expected, TransferStatus $actual): self
    {
        return new self(\sprintf('Invalid transfer transition: expected status %s but was %s.', $expected->value, $actual->value));
    }

    public static function cannotFail(TransferStatus $actual): self
    {
        return new self(\sprintf('A transfer in status %s cannot be failed.', $actual->value));
    }
}
