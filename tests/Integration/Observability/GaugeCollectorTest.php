<?php

declare(strict_types=1);

namespace App\Tests\Integration\Observability;

use App\Accounts\Application\DepositFunds;
use App\Accounts\Application\OpenAccount;
use App\Accounts\Domain\AccountId;
use App\Messaging\CommandBus;
use App\Observability\Metrics\GaugeCollector;
use App\Observability\Metrics\InMemoryMetrics;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GaugeCollectorTest extends KernelTestCase
{
    private static bool $migrated = false;

    #[Test]
    public function collectsOutboxPendingAndHoldsAndLag(): void
    {
        $kernel = self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        if (!self::$migrated) {
            $tester = new CommandTester(new Application($kernel)->find('doctrine:migrations:migrate'));
            $tester->execute(['--no-interaction' => true], ['interactive' => false]);
            self::$migrated = true;
        }
        $connection->executeStatement('TRUNCATE events RESTART IDENTITY; TRUNCATE account_balances, projection_checkpoints');

        // Two events (AccountOpened + FundsDeposited), none relayed or projected.
        $bus = self::getContainer()->get(CommandBus::class);
        self::assertInstanceOf(CommandBus::class, $bus);
        $accountId = AccountId::generate();
        $bus->dispatch(new OpenAccount($accountId, Currency::of('USD')));
        $bus->dispatch(new DepositFunds($accountId, Money::of(10_000, Currency::of('USD'))));

        $collector = self::getContainer()->get(GaugeCollector::class);
        self::assertInstanceOf(GaugeCollector::class, $collector);
        $metrics = self::getContainer()->get(InMemoryMetrics::class);
        self::assertInstanceOf(InMemoryMetrics::class, $metrics);

        $collector->collect();

        self::assertSame(2.0, $metrics->gauge('outbox_pending'));
        self::assertSame(0.0, $metrics->gauge('holds_active'));
        self::assertNotNull($metrics->gauge('projection_lag_seconds'));
        self::assertGreaterThanOrEqual(0.0, $metrics->gauge('projection_lag_seconds'));
    }
}
