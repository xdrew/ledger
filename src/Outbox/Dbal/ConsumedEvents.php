<?php

declare(strict_types=1);

namespace App\Outbox\Dbal;

use Doctrine\DBAL\Connection;

/**
 * Per-consumer idempotency guard. A consumer marks an event consumed before
 * applying its effect; a re-delivered event is recognised and skipped.
 */
final readonly class ConsumedEvents
{
    public function __construct(private Connection $connection) {}

    /**
     * Atomically record that $consumer is processing $eventId.
     *
     * @return bool true if this is the first time (apply the effect); false if
     *              already consumed (skip)
     */
    public function markConsumed(string $consumer, string $eventId): bool
    {
        $inserted = $this->connection->executeStatement(
            'INSERT INTO consumed_events (consumer, event_id) VALUES (:consumer, :event_id)
             ON CONFLICT (consumer, event_id) DO NOTHING',
            ['consumer' => $consumer, 'event_id' => $eventId],
        );

        return $inserted === 1;
    }
}
