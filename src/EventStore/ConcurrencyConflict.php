<?php

declare(strict_types=1);

namespace App\EventStore;

/**
 * Raised when an append's expected stream version does not match the stored
 * version — the optimistic-concurrency guard. Surfaces as a retriable conflict.
 */
final class ConcurrencyConflict extends \RuntimeException
{
    public static function forStream(StreamId $streamId, int $expectedVersion, int $actualVersion): self
    {
        return new self(\sprintf(
            'Concurrency conflict on stream "%s": expected version %d but found %d.',
            $streamId->toString(),
            $expectedVersion,
            $actualVersion,
        ));
    }
}
