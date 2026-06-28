<?php

declare(strict_types=1);

namespace App\EventStore\Aggregate;

use App\SharedKernel\Event\DomainEvent;

/**
 * Base class for event-sourced aggregates.
 *
 * Subclasses call {@see recordThat()} to emit a new event (which both stages it
 * for persistence and mutates in-memory state via {@see apply()}), and are
 * rebuilt from history with {@see reconstituteFromHistory()} (which replays
 * events through {@see apply()} without re-recording them).
 */
abstract class AggregateRoot
{
    private int $version = 0;

    /** @var list<DomainEvent> */
    private array $uncommittedEvents = [];

    final public function aggregateVersion(): int
    {
        return $this->version;
    }

    /**
     * Return and clear the events recorded since the aggregate was loaded.
     *
     * @return list<DomainEvent>
     */
    final public function pullUncommittedEvents(): array
    {
        $events = $this->uncommittedEvents;
        $this->uncommittedEvents = [];

        return $events;
    }

    /**
     * Rebuild an aggregate by replaying its history. Events are applied but not
     * re-recorded; the version is advanced to the last event.
     */
    final public static function reconstituteFromHistory(DomainEvent ...$events): static
    {
        $aggregate = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        foreach ($events as $event) {
            $aggregate->apply($event);
            ++$aggregate->version;
        }

        return $aggregate;
    }

    final protected function recordThat(DomainEvent $event): void
    {
        $this->apply($event);
        ++$this->version;
        $this->uncommittedEvents[] = $event;
    }

    abstract protected function apply(DomainEvent $event): void;
}
