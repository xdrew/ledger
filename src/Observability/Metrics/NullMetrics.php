<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * Discards all metrics. The default when RoadRunner's metrics RPC is not
 * available (CLI, tests that don't assert metrics).
 */
final class NullMetrics implements Metrics
{
    public function incrementCounter(string $name, array $labels = []): void {}

    public function setGauge(string $name, float $value, array $labels = []): void {}

    public function observeHistogram(string $name, float $value, array $labels = []): void {}
}
