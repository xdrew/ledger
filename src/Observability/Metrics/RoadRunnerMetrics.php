<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Spiral\RoadRunner\Metrics\Collector;
use Spiral\RoadRunner\Metrics\MetricsInterface;

/**
 * Emits metrics through RoadRunner's metrics plugin (RPC). RoadRunner aggregates
 * across worker processes and exposes them for Prometheus on the plugin's port —
 * which a per-process PHP client could not do. The business collectors are
 * declared once per worker on construction (declaration is idempotent-guarded).
 */
final class RoadRunnerMetrics implements Metrics
{
    public function __construct(private readonly MetricsInterface $metrics)
    {
        $this->declare(Metric::TRANSFERS_TOTAL, Collector::counter()->withHelp('Transfers by terminal status.')->withLabels('status'));
        $this->declare(Metric::JOURNAL_ENTRIES_TOTAL, Collector::counter()->withHelp('Journal entries posted.'));
        $this->declare(Metric::IDEMPOTENCY_REPLAYS_TOTAL, Collector::counter()->withHelp('Idempotent request replays.'));
        $this->declare(Metric::HOLDS_ACTIVE, Collector::gauge()->withHelp('Currently reserved (held) funds count.'));
        $this->declare(Metric::OUTBOX_PENDING, Collector::gauge()->withHelp('Events awaiting outbox relay.'));
        $this->declare(Metric::PROJECTION_LAG_SECONDS, Collector::gauge()->withHelp('Projection lag in seconds.'));
    }

    /**
     * @param non-empty-string $name
     */
    public function incrementCounter(string $name, array $labels = []): void
    {
        $this->metrics->add($name, 1, $this->labelValues($labels));
    }

    /**
     * @param non-empty-string $name
     */
    public function setGauge(string $name, float $value, array $labels = []): void
    {
        $this->metrics->set($name, $value, $this->labelValues($labels));
    }

    /**
     * @param non-empty-string $name
     */
    public function observeHistogram(string $name, float $value, array $labels = []): void
    {
        $this->metrics->observe($name, $value, $this->labelValues($labels));
    }

    /**
     * @param array<string, string> $labels
     * @return list<non-empty-string>
     */
    private function labelValues(array $labels): array
    {
        $values = [];
        foreach ($labels as $value) {
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param non-empty-string $name
     */
    private function declare(string $name, Collector $collector): void
    {
        try {
            $this->metrics->declare($name, $collector);
        } catch (\Throwable) {
            // Already declared by an earlier worker request — safe to ignore.
        }
    }
}
