<?php

declare(strict_types=1);

namespace App\Api\EventStream;

use App\EventStore\RecordedEvent;

/**
 * A read-only view of an aggregate's recorded event stream — the showcase's
 * "what was actually recorded" surface, shared by the account and transfer
 * event endpoints.
 */
final readonly class Response implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function __construct(
        public string $streamType,
        public string $streamId,
        public array $events,
    ) {}

    /**
     * @param list<RecordedEvent> $events
     */
    public static function fromRecordedEvents(string $streamType, string $streamId, array $events): self
    {
        return new self(
            $streamType,
            $streamId,
            array_map(
                static fn(RecordedEvent $event): array => [
                    'globalPosition' => $event->globalPosition,
                    'version' => $event->version,
                    'type' => $event->eventType,
                    'schemaVersion' => $event->schemaVersion,
                    'payload' => $event->event->toPayload(),
                    'correlationId' => $event->metadata->correlationId,
                    'causationId' => $event->metadata->causationId,
                    'occurredAt' => $event->occurredAt->format(\DateTimeInterface::ATOM),
                ],
                $events,
            ),
        );
    }

    /**
     * @return array{streamType: string, streamId: string, events: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return ['streamType' => $this->streamType, 'streamId' => $this->streamId, 'events' => $this->events];
    }
}
