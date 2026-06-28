<?php

declare(strict_types=1);

namespace App\EventStore;

use App\SharedKernel\Event\DomainEvent;

/**
 * Append-only event store port.
 *
 * Implementations persist events into per-stream sequences with optimistic
 * concurrency, assign a store-wide global position, and expose reads by stream
 * (for aggregate rehydration) and by global position (for projections/outbox).
 */
interface EventStore
{
    /**
     * Atomically append events to a stream.
     *
     * @param list<DomainEvent> $events
     *
     * @throws ConcurrencyConflict if $expectedVersion does not match the stream's current version
     */
    public function append(StreamId $streamId, int $expectedVersion, array $events, ?EventMetadata $metadata = null): void;

    /**
     * Load a stream's events in ascending version order.
     *
     * @return list<RecordedEvent>
     */
    public function load(StreamId $streamId): array;

    /**
     * Read events across all streams in ascending global position order,
     * starting strictly after the given position.
     *
     * @return list<RecordedEvent>
     */
    public function readFrom(int $afterPosition, int $limit = 100): array;
}
