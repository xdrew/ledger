<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

/**
 * The storable form of an event: its stable type name, schema version, and
 * JSON-serializable payload.
 */
final readonly class SerializedEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $type,
        public int $schemaVersion,
        public array $payload,
    ) {}
}
