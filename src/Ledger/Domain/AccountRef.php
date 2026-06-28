<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

/**
 * Opaque reference to an account a leg posts against. Keeps the ledger context
 * decoupled from Accounts\Domain — only the id string is shared.
 */
final class AccountRef
{
    private function __construct(public readonly string $value) {}

    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Account reference must be non-empty.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
