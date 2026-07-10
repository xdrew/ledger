<?php

declare(strict_types=1);

namespace App\EventStore;

/**
 * Cross-cutting metadata carried alongside an event: correlation and causation
 * ids propagated from the originating command into events, logs, and traces.
 */
final readonly class EventMetadata
{
    public function __construct(
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $traceparent = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public function withTraceparent(?string $traceparent): self
    {
        return new self($this->correlationId, $this->causationId, $traceparent);
    }
}
