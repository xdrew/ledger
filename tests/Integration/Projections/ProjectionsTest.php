<?php

declare(strict_types=1);

namespace App\Tests\Integration\Projections;

use App\Accounts\Domain\Account;
use App\Accounts\Domain\AccountId;
use App\Accounts\Infrastructure\AccountEventTypes;
use App\Accounts\Infrastructure\EventSourcedAccountRepository;
use App\EventStore\Dbal\DbalEventStore;
use App\EventStore\Serialization\EventSerializer;
use App\EventStore\Serialization\EventTypeRegistry;
use App\Projections\Dbal\AccountBalancesProjector;
use App\Projections\Dbal\AccountStatementProjector;
use App\Projections\Dbal\CheckpointStore;
use App\Projections\ProjectionRunner;
use App\Projections\Query\AccountBalanceView;
use App\Projections\Query\AccountStatementView;
use App\SharedKernel\Clock\SystemClock;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ProjectionsTest extends KernelTestCase
{
    private static bool $migrated = false;

    private Connection $connection;

    private EventSourcedAccountRepository $accounts;

    private ProjectionRunner $runner;

    private AccountBalanceView $balances;

    private AccountStatementView $statements;

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
        $this->connection->executeStatement('TRUNCATE account_balances, account_statement, projection_checkpoints');

        $registry = new EventTypeRegistry();
        new AccountEventTypes()->registerInto($registry);
        $store = new DbalEventStore($this->connection, new EventSerializer($registry), new SystemClock());
        $this->accounts = new EventSourcedAccountRepository($store);
        $checkpoints = new CheckpointStore($this->connection);
        $this->runner = new ProjectionRunner(
            $store,
            $this->connection,
            $checkpoints,
            [new AccountBalancesProjector($this->connection), new AccountStatementProjector($this->connection)],
        );
        $this->balances = new AccountBalanceView($this->connection);
        $this->statements = new AccountStatementView($this->connection);
    }

    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    private function openWithDepositAndHold(int $deposit, int $hold): AccountId
    {
        $id = AccountId::generate();
        $account = Account::open($id, Currency::of('USD'));
        $account->deposit($this->usd($deposit));
        $account->hold($this->usd($hold));
        $this->accounts->save($account);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function balancesSnapshot(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT account_id, currency, available, reserved, total, version FROM account_balances ORDER BY account_id',
        );
    }

    #[Test]
    public function balancesReflectAccountActivity(): void
    {
        $id = $this->openWithDepositAndHold(10_000, 4_000);

        $this->runner->run();

        $balance = $this->balances->find($id->toString());
        self::assertNotNull($balance);
        self::assertTrue($balance->available->equals($this->usd(6_000)));
        self::assertTrue($balance->reserved->equals($this->usd(4_000)));
        self::assertTrue($balance->total->equals($this->usd(10_000)));
        self::assertSame(3, $balance->version);
    }

    #[Test]
    public function statementListsEntriesInOrder(): void
    {
        $id = $this->openWithDepositAndHold(10_000, 4_000);

        $this->runner->run();

        $entries = $this->statements->forAccount($id->toString());
        self::assertCount(2, $entries);
        self::assertSame('deposit', $entries[0]->entryType);
        self::assertTrue($entries[0]->amount->equals($this->usd(10_000)));
        self::assertSame('hold', $entries[1]->entryType);
        self::assertTrue($entries[1]->amount->equals($this->usd(4_000)));
        self::assertLessThan($entries[1]->globalPosition, $entries[0]->globalPosition);
    }

    #[Test]
    public function dropAndRebuildYieldsIdenticalBalances(): void
    {
        $this->openWithDepositAndHold(10_000, 4_000);
        $this->openWithDepositAndHold(7_000, 1_000);

        $this->runner->run();
        $live = $this->balancesSnapshot();
        self::assertNotEmpty($live);

        $this->runner->rebuild();
        $rebuilt = $this->balancesSnapshot();

        self::assertEquals($live, $rebuilt);
    }

    #[Test]
    public function projectionIsExactlyOnceAndLagIsObservable(): void
    {
        $id = $this->openWithDepositAndHold(10_000, 4_000);

        $this->runner->run();
        self::assertSame(0, $this->runner->lag());

        $before = $this->balancesSnapshot();
        $this->runner->run();
        self::assertEquals($before, $this->balancesSnapshot(), 'Re-running must not double-apply.');

        // Append one more event; lag reflects it until processed.
        $account = $this->accounts->load($id);
        $account->deposit($this->usd(1_000));
        $this->accounts->save($account);

        self::assertSame(1, $this->runner->lag());
        $this->runner->run();
        self::assertSame(0, $this->runner->lag());

        $balance = $this->balances->find($id->toString());
        self::assertNotNull($balance);
        self::assertTrue($balance->total->equals($this->usd(11_000)));
    }
}
