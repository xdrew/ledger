<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\EventStore\Aggregate\AggregateRoot;
use App\SharedKernel\Event\DomainEvent;

/**
 * A minimal event-sourced aggregate for testing {@see AggregateRoot}.
 */
final class Counter extends AggregateRoot
{
    public int $total = 0;

    /** @var list<string> */
    public array $log = [];

    public static function start(): self
    {
        $counter = new self();
        $counter->recordThat(new SomethingHappened('start', 0));

        return $counter;
    }

    public function bump(int $by): void
    {
        $this->recordThat(new SomethingHappened('bump', $by));
    }

    protected function apply(DomainEvent $event): void
    {
        if ($event instanceof SomethingHappened) {
            $this->total += $event->amount;
            $this->log[] = $event->what;
        }
    }
}
