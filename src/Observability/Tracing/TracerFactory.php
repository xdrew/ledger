<?php

declare(strict_types=1);

namespace App\Observability\Tracing;

use App\Observability\Logging\CorrelationContext;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
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

        $provider = new TracerProvider(new SimpleSpanProcessor($this->exporter()));

        return new OtelTracer(
            $provider->getTracer('ledger-core'),
            $this->correlation,
            TraceContextPropagator::getInstance(),
        );
    }

    private function exporter(): SpanExporterInterface
    {
        if ($this->otlpEndpoint === '') {
            return new InMemoryExporter();
        }

        $transport = (new OtlpHttpTransportFactory())
            ->create(rtrim($this->otlpEndpoint, '/') . '/v1/traces', 'application/x-protobuf');

        return new OtlpSpanExporter($transport);
    }
}
