<?php

declare(strict_types=1);

namespace App\Accounts\Domain;

use Ramsey\Uuid\Uuid;

/**
 * Identity of an account aggregate (and the id of its event stream).
 */
final class AccountId
{
    private function __construct(public readonly string $value) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid account id: "%s".', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
