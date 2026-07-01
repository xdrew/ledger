<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Spiral\Goridge\RPC\RPC;
use Spiral\RoadRunner\Metrics\Metrics as RoadRunnerMetricsClient;

/**
 * Chooses the metrics adapter at runtime: the RoadRunner adapter when running
 * under RoadRunner (it injects the RPC address as the RR_RPC env var into both
 * workers and service processes), otherwise a no-op so console commands and
 * tests run without a metrics backend.
 */
final class MetricsFactory
{
    public function create(): Metrics
    {
        $address = $this->rrRpcAddress();
        if ($address === '') {
            return new NullMetrics();
        }

        try {
            return new RoadRunnerMetrics(new RoadRunnerMetricsClient(RPC::create($address)));
        } catch (\Throwable) {
            return new NullMetrics();
        }
    }

    private function rrRpcAddress(): string
    {
        foreach ([$_SERVER['RR_RPC'] ?? null, $_ENV['RR_RPC'] ?? null, getenv('RR_RPC')] as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
