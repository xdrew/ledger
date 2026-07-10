<?php

declare(strict_types=1);

namespace App\Tests\Integration\Idempotency;

use App\Idempotency\Dbal\DbalIdempotencyStore;
use App\Idempotency\IdempotencyKey;
use App\Idempotency\Outcome\Begun;
use App\Idempotency\Outcome\Completed;
use App\Idempotency\Outcome\InProgress;
use App\Idempotency\Outcome\Mismatch;
use App\Idempotency\StoredResponse;
use App\Idempotency\Ttl;
use App\Tests\Support\FixedClock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DbalIdempotencyStoreTest extends KernelTestCase
{
    private const string ROUTE = 'POST /transfers';

    private static bool $migrated = false;

    private Connection $connection;

    private FixedClock $clock;

    private DbalIdempotencyStore $store;

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

        $this->connection->executeStatement('TRUNCATE idempotency_keys');

        $this->clock = new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->store = new DbalIdempotencyStore($this->connection, $this->clock, Ttl::ofSeconds(60));
    }

    private function key(string $value = 'key-1'): IdempotencyKey
    {
        return IdempotencyKey::fromString($value);
    }

    #[Test]
    public function beginCompleteReplayRoundTrip(): void
    {
        self::assertInstanceOf(Begun::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));

        $this->store->complete($this->key(), self::ROUTE, new StoredResponse(201, ['Content-Type' => 'application/json'], '{"id":1}'));

        $outcome = $this->store->begin($this->key(), self::ROUTE, 'hash-1');
        self::assertInstanceOf(Completed::class, $outcome);
        self::assertSame(201, $outcome->response->status);
        self::assertSame(['Content-Type' => 'application/json'], $outcome->response->headers);
        self::assertSame('{"id":1}', $outcome->response->body);
    }

    #[Test]
    public function inFlightKeyConflictsAndMismatchedPayloadIsRejected(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');

        self::assertInstanceOf(InProgress::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
        self::assertInstanceOf(Mismatch::class, $this->store->begin($this->key(), self::ROUTE, 'hash-2'));
    }

    #[Test]
    public function concurrentDuplicatesYieldExactlyOneBegun(): void
    {
        // A second, independent connection competes for the same key.
        $secondConnection = DriverManager::getConnection($this->connection->getParams());
        $competingStore = new DbalIdempotencyStore($secondConnection, $this->clock, Ttl::ofSeconds(60));

        $first = $this->store->begin($this->key(), self::ROUTE, 'hash-1');
        $second = $competingStore->begin($this->key(), self::ROUTE, 'hash-1');

        $begunCount = ($first instanceof Begun ? 1 : 0) + ($second instanceof Begun ? 1 : 0);
        self::assertSame(1, $begunCount, 'Exactly one concurrent begin must win.');
        self::assertInstanceOf(InProgress::class, $second);

        $secondConnection->close();
    }

    #[Test]
    public function expiredCompletedKeyIsReclaimed(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');
        $this->store->complete($this->key(), self::ROUTE, new StoredResponse(200, [], 'ok'));

        $this->clock->set(new \DateTimeImmutable('2026-01-01T00:02:00+00:00')); // +120s > 60s TTL

        self::assertInstanceOf(Begun::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }

    #[Test]
    public function releasedKeyCanBeRetriedImmediately(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');

        $this->store->release($this->key(), self::ROUTE);

        self::assertInstanceOf(Begun::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }

    #[Test]
    public function releasingACompletedKeyKeepsTheStoredResponse(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');
        $this->store->complete($this->key(), self::ROUTE, new StoredResponse(200, [], 'ok'));

        $this->store->release($this->key(), self::ROUTE);

        self::assertInstanceOf(Completed::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }

    #[Test]
    public function staleInProgressKeyIsReclaimed(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');

        $this->clock->set(new \DateTimeImmutable('2026-01-01T00:06:00+00:00')); // +360s > 300s staleness

        self::assertInstanceOf(Begun::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }
}
