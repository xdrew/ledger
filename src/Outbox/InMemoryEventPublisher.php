<?php

declare(strict_types=1);

namespace App\Outbox;

use App\EventStore\RecordedEvent;

/**
 * Records published events in memory for tests.
 */
final class InMemoryEventPublisher implements EventPublisher
{
    /** @var list<RecordedEvent> */
    private array $published = [];

    public function publish(RecordedEvent $event): void
    {
        $this->published[] = $event;
    }

    /**
     * @return list<RecordedEvent>
     */
    public function published(): array
    {
        return $this->published;
    }

    /**
     * @return list<int>
     */
    public function publishedPositions(): array
    {
        return array_map(static fn(RecordedEvent $event): int => $event->globalPosition ?? 0, $this->published);
    }
}
