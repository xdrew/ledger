<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use App\Outbox\Dbal\RelayCheckpoint;
use App\Projections\Dbal\CheckpointStore;
use App\SharedKernel\Clock\Clock;
use Doctrine\DBAL\Connection;

/**
 * Computes the point-in-time gauges from database state and pushes them to the
 * metrics backend: `holds_active`, `outbox_pending` (events not yet relayed) and
 * `projection_lag_seconds` (age of the oldest event the projections have not yet
 * processed). Run on an interval by the RoadRunner service scheduler.
 */
final readonly class GaugeCollector
{
    public function __construct(
        private Connection $connection,
        private RelayCheckpoint $relayCheckpoint,
        private CheckpointStore $projectionCheckpoint,
        private Clock $clock,
        private Metrics $metrics,
    ) {}

    public function collect(): void
    {
        $this->metrics->setGauge(Metric::HOLDS_ACTIVE, (float) $this->holdsActive());
        $this->metrics->setGauge(Metric::OUTBOX_PENDING, (float) $this->outboxPending());
        $this->metrics->setGauge(Metric::PROJECTION_LAG_SECONDS, (float) $this->projectionLagSeconds());
    }

    private function holdsActive(): int
    {
        return $this->asInt($this->connection->fetchOne('SELECT COUNT(*) FROM account_balances WHERE reserved > 0'));
    }

    private function outboxPending(): int
    {
        $head = $this->asInt($this->connection->fetchOne('SELECT COALESCE(MAX(global_position), 0) FROM events'));

        return max(0, $head - $this->relayCheckpoint->position());
    }

    private function projectionLagSeconds(): int
    {
        $occurredAt = $this->connection->fetchOne(
            'SELECT occurred_at FROM events WHERE global_position > :pos ORDER BY global_position ASC LIMIT 1',
            ['pos' => $this->projectionCheckpoint->position()],
        );
        if (!\is_string($occurredAt)) {
            return 0;
        }

        $oldest = new \DateTimeImmutable($occurredAt);

        return max(0, $this->clock->now()->getTimestamp() - $oldest->getTimestamp());
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
