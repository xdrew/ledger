<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventStore;

use App\EventStore\ConcurrencyConflict;
use App\EventStore\InMemory\InMemoryEventStore;
use App\EventStore\Serialization\EventTypeRegistry;
use App\EventStore\Serialization\UnknownEventType;
use App\EventStore\StreamId;
use App\Tests\Support\FixedClock;
use App\Tests\Support\SomethingHappened;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryEventStoreTest extends TestCase
{
    private function store(): InMemoryEventStore
    {
        $registry = new EventTypeRegistry();
        $registry->register(SomethingHappened::TYPE, SomethingHappened::class, 1);

        return new InMemoryEventStore(new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')), $registry);
    }

    #[Test]
    public function appendsAndLoadsEventsInOrder(): void
    {
        $store = $this->store();
        $stream = StreamId::of('counter', 'c-1');

        $store->append($stream, 0, [new SomethingHappened('a', 1), new SomethingHappened('b', 2)]);

        $loaded = $store->load($stream);
        self::assertCount(2, $loaded);
        self::assertSame('a', self::payloadWhat($loaded[0]->event));
        self::assertSame('b', self::payloadWhat($loaded[1]->event));
    }

    #[Test]
    public function assignsContiguousVersions(): void
    {
        $store = $this->store();
        $stream = StreamId::of('counter', 'c-1');

        $store->append($stream, 0, [new SomethingHappened('a', 1), new SomethingHappened('b', 2), new SomethingHappened('c', 3)]);

        self::assertSame([1, 2, 3], array_map(static fn($r) => $r->version, $store->load($stream)));
    }

    #[Test]
    public function newStreamExpectsVersionZero(): void
    {
        $store = $this->store();
        $stream = StreamId::of('counter', 'fresh');

        $store->append($stream, 0, [new SomethingHappened('a', 1)]);

        self::assertCount(1, $store->load($stream));
    }

    #[Test]
    public function rejectsStaleExpectedVersionWithoutPersisting(): void
    {
        $store = $this->store();
        $stream = StreamId::of('counter', 'c-1');
        $store->append($stream, 0, [new SomethingHappened('a', 1)]);

        try {
            // Second append still expects version 0 — stale.
            $store->append($stream, 0, [new SomethingHappened('b', 2)]);
            self::fail('Expected ConcurrencyConflict.');
        } catch (ConcurrencyConflict) {
            // expected
        }

        self::assertCount(1, $store->load($stream), 'No events from the rejected append should be persisted.');
    }

    #[Test]
    public function assignsIncreasingGlobalPositionsAcrossStreams(): void
    {
        $store = $this->store();
        $store->append(StreamId::of('counter', 'a'), 0, [new SomethingHappened('a', 1)]);
        $store->append(StreamId::of('counter', 'b'), 0, [new SomethingHappened('b', 2)]);

        $all = $store->readFrom(0);
        self::assertSame([1, 2], array_map(static fn($r) => $r->globalPosition, $all));
    }

    #[Test]
    public function readFromReturnsOnlyLaterEventsInOrder(): void
    {
        $store = $this->store();
        $stream = StreamId::of('counter', 'c-1');
        $store->append($stream, 0, [new SomethingHappened('a', 1), new SomethingHappened('b', 2), new SomethingHappened('c', 3)]);

        $after = $store->readFrom(1);
        self::assertSame([2, 3], array_map(static fn($r) => $r->globalPosition, $after));
    }

    #[Test]
    public function unknownEventTypeIsRejected(): void
    {
        // Registry without the event registered.
        $store = new InMemoryEventStore(new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')), new EventTypeRegistry());

        $this->expectException(UnknownEventType::class);
        $store->append(StreamId::of('counter', 'c-1'), 0, [new SomethingHappened('a', 1)]);
    }

    private static function payloadWhat(object $event): string
    {
        self::assertInstanceOf(SomethingHappened::class, $event);

        return $event->what;
    }
}
