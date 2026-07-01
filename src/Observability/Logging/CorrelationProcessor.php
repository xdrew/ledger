<?php

declare(strict_types=1);

namespace App\Observability\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds the correlation, causation, and trace ids to every log record's `extra`,
 * so the JSON logs can be joined to traces and to the originating request.
 */
final readonly class CorrelationProcessor implements ProcessorInterface
{
    public function __construct(private CorrelationContext $context) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        if ($this->context->correlationId !== null) {
            $extra['correlation_id'] = $this->context->correlationId;
        }
        if ($this->context->causationId !== null) {
            $extra['causation_id'] = $this->context->causationId;
        }
        if ($this->context->traceId !== null) {
            $extra['trace_id'] = $this->context->traceId;
        }

        return $record->with(extra: $extra);
    }
}
