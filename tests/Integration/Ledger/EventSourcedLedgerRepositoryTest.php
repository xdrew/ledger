<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ledger;

use App\EventStore\Dbal\DbalEventStore;
use App\EventStore\Serialization\EventSerializer;
use App\EventStore\Serialization\EventTypeRegistry;
use App\Ledger\Domain\AccountRef;
use App\Ledger\Domain\JournalEntry;
use App\Ledger\Domain\JournalEntryId;
use App\Ledger\Domain\LedgerRepository;
use App\Ledger\Domain\Leg;
use App\Ledger\Domain\LegDirection;
use App\Ledger\Infrastructure\EventSourcedLedgerRepository;
use App\Ledger\Infrastructure\LedgerEventTypes;
use App\SharedKernel\Clock\SystemClock;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EventSourcedLedgerRepositoryTest extends KernelTestCase
{
    private static bool $migrated = false;

    private Connection $connection;

    private LedgerRepository $repository;

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
        new LedgerEventTypes()->registerInto($registry);
        $store = new DbalEventStore($this->connection, new EventSerializer($registry), new SystemClock());
        $this->repository = new EventSourcedLedgerRepository($store);
    }

    #[Test]
    public function postSaveAndReloadRoundTrip(): void
    {
        $id = JournalEntryId::generate();
        $entry = JournalEntry::post(
            $id,
            Leg::debit(AccountRef::fromString('acc-a'), Money::of(10_000, Currency::of('USD'))),
            Leg::credit(AccountRef::fromString('acc-b'), Money::of(10_000, Currency::of('USD'))),
        );
        $this->repository->save($entry);

        $reloaded = $this->repository->load($id);

        self::assertTrue($reloaded->id()->equals($id));
        $legs = $reloaded->legs();
        self::assertCount(2, $legs);
        self::assertSame('acc-a', $legs[0]->account->value);
        self::assertSame(LegDirection::Debit, $legs[0]->direction);
        self::assertTrue($legs[0]->amount->equals(Money::of(10_000, Currency::of('USD'))));
        self::assertSame('acc-b', $legs[1]->account->value);
        self::assertSame(LegDirection::Credit, $legs[1]->direction);
    }
}
