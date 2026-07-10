<?php

declare(strict_types=1);

namespace App\Outbox;

use App\EventStore\RecordedEvent;
use Doctrine\DBAL\Connection;

/**
 * Publishes events over PostgreSQL LISTEN/NOTIFY (demo transport). Listeners on
 * the channel receive the event id + global position and pull the event.
 */
final readonly class PostgresNotifyEventPublisher implements EventPublisher
{
    public function __construct(
        private Connection $connection,
        private string $channel = 'ledger_events',
    ) {}

    public function publish(RecordedEvent $event): void
    {
        $payload = json_encode([
            'event_id' => $event->eventId->toString(),
            'global_position' => $event->globalPosition,
            'event_type' => $event->eventType,
        ], JSON_THROW_ON_ERROR);

        $this->connection->executeStatement(
            'SELECT pg_notify(:channel, :payload)',
            ['channel' => $this->channel, 'payload' => $payload],
        );
    }
}
