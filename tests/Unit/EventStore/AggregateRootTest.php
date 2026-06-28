<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventStore;

use App\Tests\Support\Counter;
use App\Tests\Support\SomethingHappened;
use PHPUnit\Framework\TestCase;

final class AggregateRootTest extends TestCase
{
    public function testRecordingStagesEventsAndMutatesState(): void
    {
        $counter = Counter::start();
        $counter->bump(5);

        self::assertSame(5, $counter->total);
        self::assertSame(2, $counter->aggregateVersion());
        self::assertCount(2, $counter->pullUncommittedEvents());
    }

    public function testPullingUncommittedEventsClearsThem(): void
    {
        $counter = Counter::start();
        $counter->bump(5);

        $first = $counter->pullUncommittedEvents();
        $second = $counter->pullUncommittedEvents();

        self::assertCount(2, $first);
        self::assertSame([], $second);
        self::assertSame(2, $counter->aggregateVersion(), 'Pulling does not change the version.');
    }

    public function testReconstituteFromHistoryRebuildsStateWithoutRecording(): void
    {
        $counter = Counter::reconstituteFromHistory(
            new SomethingHappened('start', 0),
            new SomethingHappened('bump', 5),
            new SomethingHappened('bump', 3),
        );

        self::assertSame(8, $counter->total);
        self::assertSame(3, $counter->aggregateVersion());
        self::assertSame([], $counter->pullUncommittedEvents(), 'Replayed events are not re-recorded.');
    }
}
