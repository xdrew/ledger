<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * Records metrics in memory for assertions in tests.
 */
final class InMemoryMetrics implements Metrics
{
    /** @var array<string, float> */
    private array $counters = [];

    /** @var array<string, float> */
    private array $gauges = [];

    /** @var array<string, list<float>> */
    private array $histograms = [];

    public function incrementCounter(string $name, array $labels = []): void
    {
        $key = $this->key($name, $labels);
        $this->counters[$key] = ($this->counters[$key] ?? 0.0) + 1.0;
    }

    public function setGauge(string $name, float $value, array $labels = []): void
    {
        $this->gauges[$this->key($name, $labels)] = $value;
    }

    public function observeHistogram(string $name, float $value, array $labels = []): void
    {
        $this->histograms[$this->key($name, $labels)][] = $value;
    }

    /**
     * @param array<string, string> $labels
     */
    public function counter(string $name, array $labels = []): float
    {
        return $this->counters[$this->key($name, $labels)] ?? 0.0;
    }

    /**
     * @param array<string, string> $labels
     */
    public function gauge(string $name, array $labels = []): ?float
    {
        return $this->gauges[$this->key($name, $labels)] ?? null;
    }

    /**
     * @param array<string, string> $labels
     * @return list<float>
     */
    public function histogram(string $name, array $labels = []): array
    {
        return $this->histograms[$this->key($name, $labels)] ?? [];
    }

    /**
     * @param array<string, string> $labels
     */
    private function key(string $name, array $labels): string
    {
        if ($labels === []) {
            return $name;
        }
        ksort($labels);

        return $name . '{' . http_build_query($labels) . '}';
    }
}
