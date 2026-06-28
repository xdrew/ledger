<?php

declare(strict_types=1);

namespace App\Outbox;

use App\EventStore\EventStore;
use App\Outbox\Dbal\RelayCheckpoint;

/**
 * Tails the event log (the outbox) from the relay checkpoint and publishes every
 * event in global order. For each event it publishes first, then advances the
 * checkpoint — so the checkpoint never moves past an unpublished event (no loss)
 * and a crash re-publishes at most the in-flight event (at-least-once).
 */
final class OutboxRelay
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly EventPublisher $publisher,
        private readonly RelayCheckpoint $checkpoint,
    ) {}

    /**
     * Publish pending events. With $maxEvents set, stops after that many (used to
     * simulate a relay interrupted mid-batch). Returns the number published.
     */
    public function relay(?int $maxEvents = null): int
    {
        $published = 0;

        while ($maxEvents === null || $published < $maxEvents) {
            $events = $this->eventStore->readFrom($this->checkpoint->position(), self::BATCH_SIZE);
            if ($events === []) {
                break;
            }

            foreach ($events as $event) {
                $this->publisher->publish($event);
                if ($event->globalPosition !== null) {
                    $this->checkpoint->save($event->globalPosition);
                }
                ++$published;

                if ($maxEvents !== null && $published >= $maxEvents) {
                    return $published;
                }
            }
        }

        return $published;
    }

    public function position(): int
    {
        return $this->checkpoint->position();
    }
}
