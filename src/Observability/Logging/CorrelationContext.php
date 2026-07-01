<?php

declare(strict_types=1);

namespace App\Observability\Logging;

/**
 * Request/worker-scoped holder for the ids threaded through logs and traces.
 * Reset between requests by RoadRunner's container reset; set from the incoming
 * request (HTTP) or from event metadata (workers).
 */
final class CorrelationContext
{
    public ?string $correlationId = null;

    public ?string $causationId = null;

    public ?string $traceId = null;

    public function set(?string $correlationId, ?string $causationId = null, ?string $traceId = null): void
    {
        $this->correlationId = $correlationId;
        $this->causationId = $causationId;
        $this->traceId = $traceId;
    }

    public function reset(): void
    {
        $this->correlationId = null;
        $this->causationId = null;
        $this->traceId = null;
    }
}
