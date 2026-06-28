<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transfers;

use App\Accounts\Infrastructure\EventSourcedAccountRepository;
use App\EventStore\Dbal\DbalEventStore;
use App\EventStore\Serialization\EventSerializer;
use App\SharedKernel\Clock\SystemClock;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Tests\Support\TransferTestEnvironment;
use App\Transfers\Application\InitiateTransfer;
use App\Transfers\Domain\TransferId;
use App\Transfers\Domain\TransferStatus;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TransferSagaTest extends KernelTestCase
{
    private static bool $migrated = false;

    private Connection $connection;

    private TransferTestEnvironment $env;

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

        $store = new DbalEventStore($this->connection, new EventSerializer(TransferTestEnvironment::registry()), new SystemClock());
        $this->env = new TransferTestEnvironment($store, new EventSourcedAccountRepository($store));
    }

    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    #[Test]
    public function aFundedTransferCompletesThroughPostgres(): void
    {
        $source = $this->env->openAccount(10_000);
        $destination = $this->env->openAccount(0);

        $transfer = $this->env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $destination, $this->usd(10_000)));

        self::assertSame(TransferStatus::Completed, $transfer->status());
        self::assertTrue($this->env->accounts->load($source)->totalBalance()->equals($this->usd(0)));
        self::assertTrue($this->env->accounts->load($destination)->availableBalance()->equals($this->usd(10_000)));
    }

    #[Test]
    public function twoTransfersFromOneSourceSettleExactlyOnce(): void
    {
        $source = $this->env->openAccount(10_000);
        $b = $this->env->openAccount(0);
        $c = $this->env->openAccount(0);

        $first = $this->env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $b, $this->usd(10_000)));
        $second = $this->env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $c, $this->usd(10_000)));

        $completed = ($first->isCompleted() ? 1 : 0) + ($second->isCompleted() ? 1 : 0);
        self::assertSame(1, $completed, 'Exactly one transfer must complete.');
        self::assertTrue($this->env->accounts->load($source)->totalBalance()->equals($this->usd(0)));
    }
}
