<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * Port for emitting business metrics, keeping the application and domain code
 * decoupled from the exposition mechanism (RoadRunner's metrics plugin in prod,
 * an in-memory recorder in tests, a no-op on the CLI).
 */
interface Metrics
{
    /**
     * @param non-empty-string $name
     * @param array<string, string> $labels
     */
    public function incrementCounter(string $name, array $labels = []): void;

    /**
     * @param non-empty-string $name
     * @param array<string, string> $labels
     */
    public function setGauge(string $name, float $value, array $labels = []): void;

    /**
     * @param non-empty-string $name
     * @param array<string, string> $labels
     */
    public function observeHistogram(string $name, float $value, array $labels = []): void;
}
