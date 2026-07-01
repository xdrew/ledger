<?php

declare(strict_types=1);

namespace App\Observability\Tracing;

use App\Observability\Logging\CorrelationContext;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Builds the tracer: a no-op unless tracing is enabled. When enabled it wires the
 * OpenTelemetry SDK. The OTLP wire-exporter is deferred to add-deployment (the
 * OTLP protobuf exporter conflicts with RoadRunner's protobuf major version), so
 * the enabled path currently uses the in-memory exporter for local/dev tracing.
 */
final readonly class TracerFactory
{
    public function __construct(
        private CorrelationContext $correlation,
        private bool $tracingEnabled,
    ) {}

    public function create(): Tracer
    {
        if (!$this->tracingEnabled) {
            return new NoopTracer();
        }

        $provider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter()));

        return new OtelTracer(
            $provider->getTracer('ledger-core'),
            $this->correlation,
            TraceContextPropagator::getInstance(),
        );
    }
}
