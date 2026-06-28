<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Exception;

use App\SharedKernel\Money\Money;

/**
 * Raised when an operation is given a non-positive amount.
 */
final class InvalidAmount extends \RuntimeException
{
    public static function mustBePositive(Money $amount): self
    {
        return new self(\sprintf('Amount must be positive, got %d %s.', $amount->minorUnits, $amount->currency->code));
    }
}
