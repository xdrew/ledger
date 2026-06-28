<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Exception;

use App\Accounts\Domain\AccountId;

/**
 * Raised when loading an account that has no event stream.
 */
final class AccountNotFound extends \RuntimeException
{
    public static function withId(AccountId $id): self
    {
        return new self(\sprintf('Account "%s" not found.', $id->toString()));
    }
}
