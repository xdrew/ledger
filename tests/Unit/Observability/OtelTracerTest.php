<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Observability\Logging\CorrelationContext;
use App\Observability\Tracing\NoopTracer;
use App\Observability\Tracing\OtelTracer;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OtelTracerTest extends TestCase
{
    private CorrelationContext $correlation;

    private InMemoryExporter $exporter;

    private OtelTracer $tracer;

    protected function setUp(): void
    {
        $this->correlation = new CorrelationContext();
        $this->exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->tracer = new OtelTracer($provider->getTracer('test'), $this->correlation, TraceContextPropagator::getInstance());
    }

    #[Test]
    public function aSpanRecordsAndExposesItsTraceparentAndTraceId(): void
    {
        $insideTraceparent = null;
        $result = $this->tracer->span('command.dispatch', function () use (&$insideTraceparent): string {
            $insideTraceparent = $this->tracer->currentTraceparent();
            self::assertNotNull($this->correlation->traceId);

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertIsString($insideTraceparent);
        self::assertCount(1, $this->exporter->getSpans());
    }

    #[Test]
    public function continuingATraceparentKeepsTheSameTraceId(): void
    {
        // The traceparent a producer would stamp onto an event.
        $stamped = $this->tracer->span('command.dispatch', fn(): ?string => $this->tracer->currentTraceparent());
        self::assertIsString($stamped);

        // The async consumer continues that trace.
        $continued = $this->tracer->continueTrace(
            $stamped,
            'projection.project',
            fn(): ?string => $this->tracer->currentTraceparent(),
        );
        self::assertIsString($continued);

        self::assertSame($this->traceId($stamped), $this->traceId($continued), 'The trace id is preserved across the async hop.');
    }

    #[Test]
    public function theNoopTracerRunsTheCallbackAndTracesNothing(): void
    {
        $tracer = new NoopTracer();

        self::assertSame(42, $tracer->span('x', static fn(): int => 42));
        self::assertNull($tracer->currentTraceparent());
    }

    private function traceId(string $traceparent): string
    {
        // W3C: version "-" trace-id (32 hex) "-" span-id "-" flags
        return explode('-', $traceparent)[1] ?? '';
    }
}
