<?php

declare(strict_types=1);

namespace App\Observability\Tracing;

/**
 * Port for distributed tracing. Callback-scoped so the pipeline seams (command
 * dispatch, event append, relay, projection) wrap their work in a span without
 * managing span lifetimes by hand. A no-op implementation is the default, so
 * nothing is required when tracing is disabled.
 */
interface Tracer
{
    /**
     * Run $callback inside a new span (child of the active one).
     *
     * @template T
     * @param non-empty-string $name
     * @param callable(): T $callback
     * @param array<string, string|int|float|bool> $attributes
     * @return T
     */
    public function span(string $name, callable $callback, array $attributes = []): mixed;

    /**
     * Run $callback inside a span that continues the trace encoded in
     * $traceparent — the asynchronous hop from a stored event to its consumer.
     *
     * @template T
     * @param non-empty-string $name
     * @param callable(): T $callback
     * @param array<string, string|int|float|bool> $attributes
     * @return T
     */
    public function continueTrace(?string $traceparent, string $name, callable $callback, array $attributes = []): mixed;

    /**
     * The W3C `traceparent` for the active span, or null when not tracing —
     * stamped into event metadata so consumers can continue the trace.
     */
    public function currentTraceparent(): ?string;
}
