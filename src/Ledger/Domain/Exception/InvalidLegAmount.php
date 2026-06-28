<?php

declare(strict_types=1);

namespace App\Ledger\Domain\Exception;

use App\SharedKernel\Money\Money;

/**
 * Raised when a journal leg is given a non-positive amount.
 */
final class InvalidLegAmount extends \RuntimeException
{
    public static function forAmount(Money $amount): self
    {
        return new self(\sprintf('Leg amount must be positive, got %d %s.', $amount->minorUnits, $amount->currency->code));
    }
}
