<?php

declare(strict_types=1);

namespace App\SharedKernel\Event;

/**
 * A domain event: an immutable fact that happened in the write model.
 *
 * Events are self-describing for persistence — they expose their payload and can
 * be reconstructed from it. The stable type name and schema version live in the
 * event type registry, decoupling storage from PHP class names.
 */
interface DomainEvent
{
    /**
     * @return array<string, mixed> JSON-serializable payload of this event
     */
    public function toPayload(): array;

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self;
}
