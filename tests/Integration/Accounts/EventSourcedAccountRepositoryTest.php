<?php

declare(strict_types=1);

namespace App\Tests\Integration\Accounts;

use App\Accounts\Domain\Account;
use App\Accounts\Domain\AccountId;
use App\Accounts\Domain\AccountRepository;
use App\Accounts\Domain\AccountStatus;
use App\Accounts\Infrastructure\AccountEventTypes;
use App\Accounts\Infrastructure\EventSourcedAccountRepository;
use App\EventStore\Dbal\DbalEventStore;
use App\EventStore\Serialization\EventSerializer;
use App\EventStore\Serialization\EventTypeRegistry;
use App\SharedKernel\Clock\SystemClock;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EventSourcedAccountRepositoryTest extends KernelTestCase
{
    private static bool $migrated = false;

    private Connection $connection;

    private AccountRepository $repository;

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

        // Build the repository over a registry configured by the same registrar
        // used in DI, so the test exercises the real serialization wiring.
        $registry = new EventTypeRegistry();
        new AccountEventTypes()->registerInto($registry);
        $store = new DbalEventStore($this->connection, new EventSerializer($registry), new SystemClock());
        $this->repository = new EventSourcedAccountRepository($store);
    }

    #[Test]
    public function openDepositHoldRoundTrip(): void
    {
        $id = AccountId::generate();
        $account = Account::open($id, Currency::of('USD'));
        $account->deposit(Money::of(10_000, Currency::of('USD')));
        $account->hold(Money::of(4_000, Currency::of('USD')));
        $this->repository->save($account);

        $reloaded = $this->repository->load($id);

        self::assertSame(AccountStatus::Open, $reloaded->status());
        self::assertSame('USD', $reloaded->currency()->code);
        self::assertTrue($reloaded->availableBalance()->equals(Money::of(6_000, Currency::of('USD'))));
        self::assertTrue($reloaded->reservedBalance()->equals(Money::of(4_000, Currency::of('USD'))));
        self::assertTrue($reloaded->totalBalance()->equals(Money::of(10_000, Currency::of('USD'))));
    }

    #[Test]
    public function subsequentOperationsAppendToTheStream(): void
    {
        $id = AccountId::generate();
        $account = Account::open($id, Currency::of('USD'));
        $account->deposit(Money::of(10_000, Currency::of('USD')));
        $this->repository->save($account);

        $loaded = $this->repository->load($id);
        $loaded->credit(Money::of(2_500, Currency::of('USD')));
        $this->repository->save($loaded);

        $reloaded = $this->repository->load($id);
        self::assertTrue($reloaded->availableBalance()->equals(Money::of(12_500, Currency::of('USD'))));
    }
}
