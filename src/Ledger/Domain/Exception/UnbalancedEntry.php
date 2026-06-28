<?php

declare(strict_types=1);

namespace App\Ledger\Domain\Exception;

/**
 * Raised when a journal entry has fewer than two legs or its debits and credits
 * do not balance for some currency.
 */
final class UnbalancedEntry extends \RuntimeException
{
    public static function tooFewLegs(int $count): self
    {
        return new self(\sprintf('A journal entry needs at least two legs, got %d.', $count));
    }

    public static function forCurrency(string $currency, int $net): self
    {
        return new self(\sprintf('Journal entry is unbalanced for %s: net %d (debits minus credits).', $currency, $net));
    }
}
