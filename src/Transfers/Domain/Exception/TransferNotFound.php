<?php

declare(strict_types=1);

namespace App\Transfers\Domain\Exception;

use App\Transfers\Domain\TransferId;

final class TransferNotFound extends \RuntimeException
{
    public static function withId(TransferId $id): self
    {
        return new self(\sprintf('Transfer "%s" not found.', $id->toString()));
    }
}
