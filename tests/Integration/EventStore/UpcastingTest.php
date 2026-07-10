<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventStore;

use App\Accounts\Domain\AccountId;
use App\Accounts\Domain\AccountRepository;
use App\Accounts\Domain\Event\AccountOpened;
use App\EventStore\EventStore;
use App\EventStore\StreamId;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves the §6 requirement end to end against PostgreSQL using the container's
 * fully wired serializer (tagged upcasters): a row stored at schema version 1 —
 * before `account_type` existed — loads at the current shape and the aggregate
 * rehydrates; the stored row itself is never touched.
 */
final class UpcastingTest extends KernelTestCase
{
    private static bool $migrated = false;

    private Connection $connection;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        if (!self::$migrated) {
            $tester = new CommandTester(new Application($kernel)->find('doctrine:migrations:migrate'));
            $tester->execute(['--no-interaction' => true], ['interactive' => false]);
            $tester->assertCommandIsSuccessful();
            self::$migrated = true;
        }

        $this->connection->executeStatement('TRUNCATE events RESTART IDENTITY');
    }

    private function insertRawV1AccountOpened(AccountId $id): void
    {
        $this->connection->executeStatement(
            "INSERT INTO events (event_id, stream_type, stream_id, version, event_type, schema_version, payload, metadata, occurred_at, recorded_at)
             VALUES (gen_random_uuid(), 'account', :stream_id, 1, 'accounts.account_opened', 1, CAST(:payload AS JSONB), '{}'::jsonb, now(), now())",
            [
                'stream_id' => $id->toString(),
                // The v1 shape: no account_type. Never "refresh" this fixture.
                'payload' => json_encode(['account_id' => $id->toString(), 'currency' => 'USD'], JSON_THROW_ON_ERROR),
            ],
        );
    }

    #[Test]
    public function aStoredV1RowLoadsAtTheCurrentShape(): void
    {
        $id = AccountId::generate();
        $this->insertRawV1AccountOpened($id);

        $store = self::getContainer()->get(EventStore::class);
        self::assertInstanceOf(EventStore::class, $store);

        $events = $store->load(StreamId::of('account', $id->toString()));
        self::assertCount(1, $events);
        $event = $events[0]->event;
        self::assertInstanceOf(AccountOpened::class, $event);
        self::assertSame(AccountOpened::DEFAULT_ACCOUNT_TYPE, $event->accountType);

        // Upcast-on-read never rewrites the stored row.
        $stored = $this->connection->fetchAssociative('SELECT schema_version, payload FROM events WHERE stream_id = :id', ['id' => $id->toString()]);
        self::assertIsArray($stored);
        $schemaVersion = $stored['schema_version'];
        self::assertIsNumeric($schemaVersion);
        self::assertSame(1, (int) $schemaVersion);
        $payload = $stored['payload'];
        self::assertIsString($payload);
        self::assertStringNotContainsString('account_type', $payload);
    }

    #[Test]
    public function anAccountWithV1HistoryRehydratesAndOperates(): void
    {
        $id = AccountId::generate();
        $this->insertRawV1AccountOpened($id);

        $accounts = self::getContainer()->get(AccountRepository::class);
        self::assertInstanceOf(AccountRepository::class, $accounts);

        $account = $accounts->load($id);
        $account->deposit(Money::of(5_000, Currency::of('USD')));
        $accounts->save($account);

        self::assertTrue($accounts->load($id)->availableBalance()->equals(Money::of(5_000, Currency::of('USD'))));
    }
}
