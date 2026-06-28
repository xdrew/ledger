<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

use App\Ledger\Domain\Exception\InvalidLegAmount;
use App\SharedKernel\Money\Money;

/**
 * One side of a journal entry: a debit or credit of a positive amount on an account.
 */
final class Leg
{
    private function __construct(
        public readonly AccountRef $account,
        public readonly LegDirection $direction,
        public readonly Money $amount,
    ) {}

    public static function of(AccountRef $account, LegDirection $direction, Money $amount): self
    {
        if (!$amount->isPositive()) {
            throw InvalidLegAmount::forAmount($amount);
        }

        return new self($account, $direction, $amount);
    }

    public static function debit(AccountRef $account, Money $amount): self
    {
        return self::of($account, LegDirection::Debit, $amount);
    }

    public static function credit(AccountRef $account, Money $amount): self
    {
        return self::of($account, LegDirection::Credit, $amount);
    }
}
