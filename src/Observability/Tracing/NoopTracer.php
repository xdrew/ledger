<?php

declare(strict_types=1);

namespace App\Observability\Tracing;

/**
 * Tracing disabled: runs the work, records nothing, propagates no context. The
 * default so tests and the CLI need no collector.
 */
final class NoopTracer implements Tracer
{
    public function span(string $name, callable $callback, array $attributes = []): mixed
    {
        return $callback();
    }

    public function continueTrace(?string $traceparent, string $name, callable $callback, array $attributes = []): mixed
    {
        return $callback();
    }

    public function currentTraceparent(): ?string
    {
        return null;
    }
}
