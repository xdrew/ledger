<?php

declare(strict_types=1);

namespace App\EventStore\InMemory;

use App\EventStore\ConcurrencyConflict;
use App\EventStore\EventMetadata;
use App\EventStore\EventStore;
use App\EventStore\RecordedEvent;
use App\EventStore\Serialization\EventTypeRegistry;
use App\EventStore\StreamId;
use App\SharedKernel\Clock\Clock;
use App\SharedKernel\Event\EventId;

/**
 * In-memory event store honouring the same contract as the production store —
 * per-stream contiguous versioning, optimistic concurrency, and global ordering
 * — so write-side unit tests run without a database.
 */
final class InMemoryEventStore implements EventStore
{
    /** @var array<string, list<RecordedEvent>> */
    private array $streams = [];

    /** @var list<RecordedEvent> */
    private array $global = [];

    private int $position = 0;

    public function __construct(
        private readonly Clock $clock,
        private readonly EventTypeRegistry $registry,
    ) {}

    public function append(StreamId $streamId, int $expectedVersion, array $events, ?EventMetadata $metadata = null): void
    {
        $key = $streamId->toString();
        $current = \count($this->streams[$key] ?? []);
        if ($current !== $expectedVersion) {
            throw ConcurrencyConflict::forStream($streamId, $expectedVersion, $current);
        }

        $metadata ??= EventMetadata::none();

        // Build (and validate types) first so an unregistered event aborts the
        // whole append before any state is mutated.
        $prepared = [];
        $version = $expectedVersion;
        foreach ($events as $event) {
            ++$version;
            $prepared[] = new RecordedEvent(
                EventId::generate(),
                $streamId,
                $version,
                $this->registry->typeForClass($event::class),
                $this->registry->schemaVersionForClass($event::class),
                $event,
                $this->clock->now(),
                $metadata,
                null,
            );
        }

        foreach ($prepared as $recorded) {
            ++$this->position;
            $stored = $recorded->withGlobalPosition($this->position);
            $this->streams[$key][] = $stored;
            $this->global[] = $stored;
        }
    }

    public function load(StreamId $streamId): array
    {
        return $this->streams[$streamId->toString()] ?? [];
    }

    public function readFrom(int $afterPosition, int $limit = 100): array
    {
        $result = [];
        foreach ($this->global as $event) {
            if ($event->globalPosition !== null && $event->globalPosition > $afterPosition) {
                $result[] = $event;
                if (\count($result) >= $limit) {
                    break;
                }
            }
        }

        return $result;
    }
}
