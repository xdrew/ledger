<?php

declare(strict_types=1);

namespace App\SharedKernel\Event;

use Ramsey\Uuid\Uuid;

/**
 * Globally unique identifier for a single event occurrence. UUIDv7 is used so
 * ids are time-ordered, which keeps index locality good and aids debugging.
 */
final readonly class EventId
{
    private function __construct(private string $value) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid event id: "%s".', $value));
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
