<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

use App\SharedKernel\Event\DomainEvent;

/**
 * Converts domain events to/from their storable form using the type registry.
 *
 * Writers always emit the type's current schema version. On read, payloads
 * stored at an older version are stepped to the current shape by the
 * {@see UpcasterChain} before `fromPayload` (upcast-on-read, ADR-006); payloads
 * already current bypass the chain.
 */
final readonly class EventSerializer
{
    private UpcasterChain $upcasters;

    public function __construct(
        private EventTypeRegistry $registry,
        ?UpcasterChain $upcasters = null,
    ) {
        $this->upcasters = $upcasters ?? new UpcasterChain();
    }

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

        $currentVersion = $this->registry->schemaVersionForClass($class);
        if ($schemaVersion < $currentVersion) {
            $payload = $this->upcasters->upcast($type, $schemaVersion, $currentVersion, $payload);
        }

        return $class::fromPayload($payload);
    }
}
