<?php

declare(strict_types=1);

namespace App\EventStore;

/**
 * Identifies an event stream: a stream type (the aggregate kind, e.g. "account")
 * plus the aggregate's own id. The pair is the unit of optimistic concurrency.
 */
final readonly class StreamId
{
    private function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function of(string $type, string $id): self
    {
        if ($type === '' || $id === '') {
            throw new \InvalidArgumentException('Stream type and id must be non-empty.');
        }

        return new self($type, $id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function toString(): string
    {
        return $this->type . '/' . $this->id;
    }
}
