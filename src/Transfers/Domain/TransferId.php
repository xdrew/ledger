<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

use Ramsey\Uuid\Uuid;

/**
 * Identity of a transfer (and the id of its saga event stream).
 */
final class TransferId
{
    private function __construct(public readonly string $value) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid transfer id: "%s".', $value));
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
