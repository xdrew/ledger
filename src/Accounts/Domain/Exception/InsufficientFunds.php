<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Exception;

use App\SharedKernel\Money\Money;

/**
 * Raised when an operation would overdraw available or reserved funds.
 */
final class InsufficientFunds extends \RuntimeException
{
    public static function available(Money $requested, Money $available): self
    {
        return new self(\sprintf(
            'Insufficient available funds: requested %d, available %d (%s).',
            $requested->minorUnits,
            $available->minorUnits,
            $available->currency->code,
        ));
    }

    public static function reserved(Money $requested, Money $reserved): self
    {
        return new self(\sprintf(
            'Insufficient reserved funds: requested %d, reserved %d (%s).',
            $requested->minorUnits,
            $reserved->minorUnits,
            $reserved->currency->code,
        ));
    }
}
