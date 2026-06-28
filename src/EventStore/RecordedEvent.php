<?php

declare(strict_types=1);

namespace App\EventStore;

use App\SharedKernel\Event\DomainEvent;
use App\SharedKernel\Event\EventId;

/**
 * An event as it exists in the store: the deserialized domain event plus its
 * envelope — identity, stream position (per-stream version), global position,
 * type/schema metadata, timestamp, and correlation/causation metadata.
 */
final class RecordedEvent
{
    public function __construct(
        public readonly EventId $eventId,
        public readonly StreamId $streamId,
        public readonly int $version,
        public readonly string $eventType,
        public readonly int $schemaVersion,
        public readonly DomainEvent $event,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly EventMetadata $metadata,
        public readonly ?int $globalPosition,
    ) {}

    public function withGlobalPosition(int $globalPosition): self
    {
        return new self(
            $this->eventId,
            $this->streamId,
            $this->version,
            $this->eventType,
            $this->schemaVersion,
            $this->event,
            $this->occurredAt,
            $this->metadata,
            $globalPosition,
        );
    }
}
