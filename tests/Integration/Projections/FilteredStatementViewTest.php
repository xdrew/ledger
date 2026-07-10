<?php

declare(strict_types=1);

namespace App\Tests\Integration\Projections;

use App\Projections\Query\AccountStatementView;
use App\Projections\Query\StatementFilter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class FilteredStatementViewTest extends KernelTestCase
{
    private static bool $migrated = false;

    private Connection $connection;

    private AccountStatementView $view;
    private const string ACCOUNT = '0195f2c0-0000-7000-8000-00000000f11e';

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        if (!self::$migrated) {
            $tester = new CommandTester(new Application($kernel)->find('doctrine:migrations:migrate'));
            $tester->execute(['--no-interaction' => true], ['interactive' => false]);
            self::$migrated = true;
        }

        $this->connection->executeStatement('TRUNCATE account_statement RESTART IDENTITY');
        $rows = [
            [1, 'deposit', 10_000, '2026-06-05 10:00:00+00'],
            [2, 'deposit', 2_500, '2026-06-20 10:00:00+00'],
            [3, 'debit', 3_000, '2026-06-25 10:00:00+00'],
            [4, 'deposit', 7_000, '2026-07-01 10:00:00+00'],
        ];
        foreach ($rows as [$position, $type, $amount, $occurredAt]) {
            $this->connection->executeStatement(
                'INSERT INTO account_statement (account_id, global_position, entry_type, amount, currency, occurred_at)
                 VALUES (:id, :pos, :type, :amount, :currency, :at)',
                ['id' => self::ACCOUNT, 'pos' => $position, 'type' => $type, 'amount' => $amount, 'currency' => 'USD', 'at' => $occurredAt],
            );
        }

        $this->view = new AccountStatementView($this->connection);
    }

    #[Test]
    public function filtersByTypeAndDateRangeAndComputesSqlAggregates(): void
    {
        $filter = StatementFilter::fromArray([
            'entry_types' => ['deposit'],
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'aggregation' => 'sum',
        ]);

        $result = $this->view->forAccountFiltered(self::ACCOUNT, $filter);

        // The June debit and the July deposit are excluded.
        self::assertCount(2, $result->entries);
        self::assertSame(2, $result->count);
        self::assertSame(12_500, $result->sumMinorUnits);
        self::assertSame('USD', $result->currency);
    }

    #[Test]
    public function filtersByAmountRange(): void
    {
        $filter = StatementFilter::fromArray([
            'min_amount' => 3_000,
            'max_amount' => 8_000,
            'aggregation' => 'count',
        ]);

        $result = $this->view->forAccountFiltered(self::ACCOUNT, $filter);

        self::assertSame(2, $result->count); // 3_000 debit + 7_000 deposit
    }

    #[Test]
    public function anUnconstrainedFilterMatchesEverything(): void
    {
        $result = $this->view->forAccountFiltered(self::ACCOUNT, StatementFilter::all());

        self::assertSame(4, $result->count);
        self::assertSame(22_500, $result->sumMinorUnits);
    }
}
