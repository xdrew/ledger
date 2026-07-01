<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventStore;

use App\EventStore\ConcurrencyConflict;
use App\EventStore\Dbal\DbalEventStore;
use App\EventStore\EventMetadata;
use App\EventStore\Serialization\EventSerializer;
use App\EventStore\Serialization\EventTypeRegistry;
use App\EventStore\StreamId;
use App\Tests\Support\FixedClock;
use App\Tests\Support\SomethingHappened;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DbalEventStoreTest extends KernelTestCase
{
    private static bool $migrated = false;

    private Connection $connection;

    private DbalEventStore $store;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        if (!self::$migrated) {
            $application = new Application($kernel);
            $tester = new CommandTester($application->find('doctrine:migrations:migrate'));
            $tester->execute(['--no-interaction' => true], ['interactive' => false]);
            $tester->assertCommandIsSuccessful();
            self::$migrated = true;
        }

        $this->connection->executeStatement('TRUNCATE events RESTART IDENTITY');

        $registry = new EventTypeRegistry();
        $registry->register(SomethingHappened::TYPE, SomethingHappened::class, 1);
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-01-01T12:00:00+00:00'));
        $this->store = new DbalEventStore($this->connection, new EventSerializer($registry), $this->clock);
    }

    #[Test]
    public function appendAndLoadRoundTrip(): void
    {
        $stream = StreamId::of('counter', 'c-1');
        $this->store->append($stream, 0, [new SomethingHappened('paid', 500)], new EventMetadata('corr-1', 'cause-1'));

        $loaded = $this->store->load($stream);
        self::assertCount(1, $loaded);

        $recorded = $loaded[0];
        self::assertInstanceOf(SomethingHappened::class, $recorded->event);
        self::assertSame('paid', $recorded->event->what);
        self::assertSame(500, $recorded->event->amount);
        self::assertSame(1, $recorded->version);
        self::assertSame(SomethingHappened::TYPE, $recorded->eventType);
        self::assertNotNull($recorded->globalPosition);
        self::assertGreaterThan(0, $recorded->globalPosition);
        self::assertSame('corr-1', $recorded->metadata->correlationId);
        self::assertSame('cause-1', $recorded->metadata->causationId);
        self::assertSame(
            $this->clock->now()->format(\DateTimeInterface::ATOM),
            $recorded->occurredAt->format(\DateTimeInterface::ATOM),
        );
    }

    #[Test]
    public function traceContextRoundTripsThroughTheStore(): void
    {
        $stream = StreamId::of('counter', 'trace-1');
        $traceparent = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
        $this->store->append($stream, 0, [new SomethingHappened('x', 1)], new EventMetadata('c', null, $traceparent));

        self::assertSame($traceparent, $this->store->load($stream)[0]->metadata->traceparent);
    }

    #[Test]
    public function eventsWithoutTraceContextStillLoad(): void
    {
        $stream = StreamId::of('counter', 'trace-2');
        $this->store->append($stream, 0, [new SomethingHappened('x', 1)], new EventMetadata('c'));

        self::assertNull($this->store->load($stream)[0]->metadata->traceparent);
    }

    #[Test]
    public function assignsContiguousVersions(): void
    {
        $stream = StreamId::of('counter', 'c-1');
        $this->store->append($stream, 0, [
            new SomethingHappened('a', 1),
            new SomethingHappened('b', 2),
            new SomethingHappened('c', 3),
        ]);

        self::assertSame([1, 2, 3], array_map(static fn($r) => $r->version, $this->store->load($stream)));
    }

    #[Test]
    public function staleExpectedVersionIsRejectedAtomically(): void
    {
        $stream = StreamId::of('counter', 'c-1');
        $this->store->append($stream, 0, [new SomethingHappened('a', 1)]);

        try {
            $this->store->append($stream, 0, [new SomethingHappened('b', 2), new SomethingHappened('c', 3)]);
            self::fail('Expected ConcurrencyConflict.');
        } catch (ConcurrencyConflict) {
            // expected
        }

        self::assertCount(1, $this->store->load($stream), 'Nothing from the rejected append should persist.');
    }

    #[Test]
    public function globalOrderingAcrossStreams(): void
    {
        $this->store->append(StreamId::of('counter', 'a'), 0, [new SomethingHappened('a', 1)]);
        $this->store->append(StreamId::of('counter', 'b'), 0, [new SomethingHappened('b', 2)]);

        $positions = array_map(static fn($r) => $r->globalPosition, $this->store->readFrom(0));
        self::assertSame([1, 2], $positions);
    }

    #[Test]
    public function readFromReturnsOnlyLaterEvents(): void
    {
        $stream = StreamId::of('counter', 'c-1');
        $this->store->append($stream, 0, [new SomethingHappened('a', 1), new SomethingHappened('b', 2)]);

        $after = $this->store->readFrom(1);
        self::assertSame([2], array_map(static fn($r) => $r->globalPosition, $after));
    }

    #[Test]
    public function uniqueConstraintGuardsStreamVersion(): void
    {
        // The DB-level guard behind the optimistic-concurrency check: a duplicate
        // (stream_type, stream_id, version) is rejected by the unique index.
        $this->store->append(StreamId::of('counter', 'c-1'), 0, [new SomethingHappened('a', 1)]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement(
            "INSERT INTO events (event_id, stream_type, stream_id, version, event_type, schema_version, payload, metadata, occurred_at, recorded_at)
             VALUES (gen_random_uuid(), 'counter', 'c-1', 1, :type, 1, '{}'::jsonb, '{}'::jsonb, now(), now())",
            ['type' => SomethingHappened::TYPE],
        );
    }
}
