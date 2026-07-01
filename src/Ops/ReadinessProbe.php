<?php

declare(strict_types=1);

namespace App\Ops;

use App\Outbox\Dbal\RelayCheckpoint;
use App\SharedKernel\Clock\Clock;
use Doctrine\DBAL\Connection;

/**
 * Determines whether the service is ready to take traffic: the database answers
 * and the outbox relay is live (its heartbeat is recent). Used by `/readyz`.
 */
final readonly class ReadinessProbe
{
    public function __construct(
        private Connection $connection,
        private RelayCheckpoint $relayCheckpoint,
        private Clock $clock,
        private int $relayMaxAgeSeconds,
    ) {}

    /**
     * @return array{ready: bool, checks: array<string, string>}
     */
    public function check(): array
    {
        $checks = [];

        $checks['database'] = $this->databaseReachable() ? 'ok' : 'unreachable';
        $checks['outbox_relay'] = $this->relayStatus();

        $ready = $checks['database'] === 'ok' && $checks['outbox_relay'] === 'ok';

        return ['ready' => $ready, 'checks' => $checks];
    }

    private function databaseReachable(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function relayStatus(): string
    {
        $last = $this->relayCheckpoint->lastHeartbeat();
        if ($last === null) {
            return 'no heartbeat';
        }

        $age = $this->clock->now()->getTimestamp() - $last->getTimestamp();

        return $age > $this->relayMaxAgeSeconds ? \sprintf('stale (%ds)', $age) : 'ok';
    }
}
