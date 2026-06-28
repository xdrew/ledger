<?php

declare(strict_types=1);

namespace App\Outbox;

use App\EventStore\RecordedEvent;

/**
 * Transport for publishing domain events out of the outbox relay. Swappable:
 * in-memory (tests), PostgreSQL LISTEN/NOTIFY (demo), NATS JetStream (future).
 */
interface EventPublisher
{
    public function publish(RecordedEvent $event): void;
}
