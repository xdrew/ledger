<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Exception;

use App\Accounts\Domain\AccountStatus;

/**
 * Raised when a balance operation is attempted on a non-open (frozen/closed) account.
 */
final class AccountNotActive extends \RuntimeException
{
    private function __construct(string $message, public readonly AccountStatus $status)
    {
        parent::__construct($message);
    }

    public static function forStatus(AccountStatus $status): self
    {
        return new self(\sprintf('Account is not active (status: %s).', $status->value), $status);
    }
}
