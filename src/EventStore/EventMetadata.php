<?php

declare(strict_types=1);

namespace App\EventStore;

/**
 * Cross-cutting metadata carried alongside an event: correlation and causation
 * ids propagated from the originating command into events, logs, and traces.
 */
final class EventMetadata
{
    public function __construct(
        public readonly ?string $correlationId = null,
        public readonly ?string $causationId = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }
}
