<?php

declare(strict_types=1);

namespace App\SharedKernel\Money;

/**
 * Raised when an operation mixes two different currencies, which is never valid.
 */
final class CurrencyMismatch extends \RuntimeException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self(\sprintf('Currency mismatch: %s vs %s.', $a->code, $b->code));
    }
}
