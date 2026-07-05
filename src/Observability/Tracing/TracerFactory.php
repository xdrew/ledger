<?php

declare(strict_types=1);

namespace App\Observability\Tracing;

use App\Observability\Logging\CorrelationContext;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Builds the tracer: a no-op unless tracing is enabled. When enabled it wires the
 * OpenTelemetry SDK, exporting spans over OTLP/HTTP to the configured collector
 * (or an in-memory exporter when no endpoint is set — local/dev).
 */
final readonly class TracerFactory
{
    public function __construct(
        private CorrelationContext $correlation,
        private bool $tracingEnabled,
        private string $otlpEndpoint = '',
    ) {}

    public function create(): Tracer
    {
        if (!$this->tracingEnabled) {
            return new NoopTracer();
        }

        // OTLP export runs through a BatchSpanProcessor so span end only queues —
        // but PHP has no background thread: the batch flush itself is synchronous,
        // so the transport must fail FAST. With the default 10s timeout × 3
        // retries an unreachable collector cost ~30s per flush and lagged the
        // projection worker by ~18s per event burst (found live by the demo).
        // 1s timeout + 1 retry bounds a broken collector to ~2s per batch.
        $processor = $this->otlpEndpoint === ''
            ? new SimpleSpanProcessor(new InMemoryExporter())
            : new BatchSpanProcessor($this->otlpExporter(), ClockFactory::getDefault());

        $provider = new TracerProvider($processor);

        return new OtelTracer(
            $provider->getTracer('ledger-core'),
            $this->correlation,
            TraceContextPropagator::getInstance(),
        );
    }

    private function otlpExporter(): OtlpSpanExporter
    {
        $transport = (new OtlpHttpTransportFactory())->create(
            rtrim($this->otlpEndpoint, '/') . '/v1/traces',
            'application/x-protobuf',
            timeout: 1.0,
            maxRetries: 1,
        );

        return new OtlpSpanExporter($transport);
    }
}
