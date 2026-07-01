<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Observability\Logging\CorrelationContext;
use App\Observability\Logging\CorrelationProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CorrelationProcessorTest extends TestCase
{
    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'something happened');
    }

    #[Test]
    public function addsTheIdsWhenPresent(): void
    {
        $context = new CorrelationContext();
        $context->set('corr-1', 'cause-1', 'trace-1');

        $record = (new CorrelationProcessor($context))($this->record());

        self::assertSame('corr-1', $record->extra['correlation_id'] ?? null);
        self::assertSame('cause-1', $record->extra['causation_id'] ?? null);
        self::assertSame('trace-1', $record->extra['trace_id'] ?? null);
    }

    #[Test]
    public function omitsIdsThatAreNotSet(): void
    {
        $context = new CorrelationContext();
        $context->correlationId = 'corr-only';

        $record = (new CorrelationProcessor($context))($this->record());

        self::assertSame('corr-only', $record->extra['correlation_id'] ?? null);
        self::assertArrayNotHasKey('causation_id', $record->extra);
        self::assertArrayNotHasKey('trace_id', $record->extra);
    }
}
