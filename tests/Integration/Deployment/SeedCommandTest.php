<?php

declare(strict_types=1);

namespace App\Tests\Integration\Deployment;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedCommandTest extends KernelTestCase
{
    private static bool $migrated = false;

    #[Test]
    public function seedingCreatesAccountsAndTransfersReflectedInTheReadModels(): void
    {
        $kernel = self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $application = new Application($kernel);
        if (!self::$migrated) {
            (new CommandTester($application->find('doctrine:migrations:migrate')))
                ->execute(['--no-interaction' => true], ['interactive' => false]);
            self::$migrated = true;
        }
        $connection->executeStatement('TRUNCATE events RESTART IDENTITY; TRUNCATE account_balances, account_statement, projection_checkpoints');

        $seed = new CommandTester($application->find('app:seed'));
        $seed->execute([], ['interactive' => false]);
        $seed->assertCommandIsSuccessful();

        // 3 demo accounts, projected.
        self::assertSame(3, $this->int($connection->fetchOne('SELECT COUNT(*) FROM account_balances')));

        // alice: +100.00 −30.00 = 70.00; bob: +50.00 +30.00 = 80.00; carol: 0.
        $available = $connection->fetchFirstColumn('SELECT available FROM account_balances ORDER BY available');
        self::assertSame([0, 7_000, 8_000], array_map($this->int(...), $available));

        // The completed transfer was recorded.
        self::assertGreaterThanOrEqual(1, $this->int($connection->fetchOne(
            "SELECT COUNT(*) FROM events WHERE event_type = 'transfers.transfer_completed'",
        )));
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
