<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Observability\Metrics\InMemoryMetrics;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Business counters are recorded through the metrics port. In the test container
 * the port is an in-memory recorder (RoadRunner's RPC is unavailable), read back
 * from the same container that handled the request.
 */
final class BusinessMetricsTest extends ApiTestCase
{
    #[Test]
    public function aCompletedTransferIncrementsItsCounter(): void
    {
        $source = $this->openAccount('USD');
        $destination = $this->openAccount('USD');
        $this->post(\sprintf('/api/accounts/%s/deposits', $source), ['amount' => 10_000, 'currency' => 'USD']);

        $this->post('/api/transfers', [
            'sourceAccountId' => $source,
            'destinationAccountId' => $destination,
            'amount' => 10_000,
            'currency' => 'USD',
        ]);
        self::assertSame(201, $this->statusCode());

        self::assertSame(1.0, $this->metrics()->counter('transfers_total', ['status' => 'completed']));
        self::assertSame(1.0, $this->metrics()->counter('journal_entries_total'));
    }

    #[Test]
    public function anIdempotentReplayIncrementsItsCounter(): void
    {
        $this->post('/api/accounts', ['currency' => 'USD'], idempotencyKey: 'metrics-replay');
        self::assertSame(201, $this->statusCode());

        $this->post('/api/accounts', ['currency' => 'USD'], idempotencyKey: 'metrics-replay');
        self::assertSame(201, $this->statusCode());

        self::assertSame(1.0, $this->metrics()->counter('idempotency_replays_total'));
    }

    private function metrics(): InMemoryMetrics
    {
        $metrics = self::getContainer()->get(InMemoryMetrics::class);
        self::assertInstanceOf(InMemoryMetrics::class, $metrics);

        return $metrics;
    }
}
