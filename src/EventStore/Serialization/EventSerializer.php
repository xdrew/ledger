<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

use App\SharedKernel\Event\DomainEvent;

/**
 * Converts domain events to/from their storable form using the type registry.
 *
 * The $schemaVersion is recorded now but not transformed on read — upcasting is
 * introduced in a later change; deserialize simply rebuilds the current shape.
 */
final class EventSerializer
{
    public function __construct(private readonly EventTypeRegistry $registry) {}

    public function serialize(DomainEvent $event): SerializedEvent
    {
        return new SerializedEvent(
            $this->registry->typeForClass($event::class),
            $this->registry->schemaVersionForClass($event::class),
            $event->toPayload(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function deserialize(string $type, int $schemaVersion, array $payload): DomainEvent
    {
        $class = $this->registry->classForType($type);

        return $class::fromPayload($payload);
    }
}
