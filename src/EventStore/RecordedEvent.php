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
final readonly class RecordedEvent
{
    public function __construct(
        public EventId $eventId,
        public StreamId $streamId,
        public int $version,
        public string $eventType,
        public int $schemaVersion,
        public DomainEvent $event,
        public \DateTimeImmutable $occurredAt,
        public EventMetadata $metadata,
        public ?int $globalPosition,
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
