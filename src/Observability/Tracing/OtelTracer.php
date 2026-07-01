<?php

declare(strict_types=1);

namespace App\Observability\Tracing;

use App\Observability\Logging\CorrelationContext;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * OpenTelemetry-backed tracer. Spans are children of the active OTel context, so
 * the pipeline seams form one trace; the active trace id is mirrored into the
 * {@see CorrelationContext} so log lines carry it.
 */
final readonly class OtelTracer implements Tracer
{
    public function __construct(
        private TracerInterface $tracer,
        private CorrelationContext $correlation,
        private TextMapPropagatorInterface $propagator,
    ) {}

    /**
     * @param non-empty-string $name
     */
    public function span(string $name, callable $callback, array $attributes = []): mixed
    {
        return $this->run($this->tracer->spanBuilder($name), $callback, $attributes);
    }

    /**
     * @param non-empty-string $name
     */
    public function continueTrace(?string $traceparent, string $name, callable $callback, array $attributes = []): mixed
    {
        $builder = $this->tracer->spanBuilder($name);
        if ($traceparent !== null && $traceparent !== '') {
            $builder = $builder->setParent($this->propagator->extract(['traceparent' => $traceparent]));
        }

        return $this->run($builder, $callback, $attributes);
    }

    public function currentTraceparent(): ?string
    {
        /** @var array<string, string> $carrier */
        $carrier = [];
        $this->propagator->inject($carrier);
        $traceparent = \is_array($carrier) ? ($carrier['traceparent'] ?? null) : null;

        return \is_string($traceparent) && $traceparent !== '' ? $traceparent : null;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param array<string, string|int|float|bool> $attributes
     * @return T
     */
    private function run(SpanBuilderInterface $builder, callable $callback, array $attributes): mixed
    {
        foreach ($attributes as $key => $value) {
            $builder = $builder->setAttribute($key, $value);
        }

        $span = $builder->startSpan();
        $scope = $span->activate();
        $previousTraceId = $this->correlation->traceId;
        $this->correlation->traceId = $span->getContext()->getTraceId();

        try {
            return $callback();
        } catch (\Throwable $error) {
            $span->recordException($error);
            $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());

            throw $error;
        } finally {
            $scope->detach();
            $span->end();
            $this->correlation->traceId = $previousTraceId;
        }
    }
}
