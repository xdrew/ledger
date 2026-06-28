<?php

declare(strict_types=1);

namespace App\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnostic: verifies the application can reach PostgreSQL using the
 * environment-configured DBAL connection. Used by `make db-ping` and as a
 * connectivity smoke check; the HTTP readiness probe arrives in
 * `add-observability`.
 */
#[AsCommand(name: 'app:db:ping', description: 'Verify database connectivity via the configured DBAL connection.')]
final class PingDatabaseCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->connection->executeQuery('SELECT 1')->fetchOne();
        if (!is_numeric($result) || (int) $result !== 1) {
            $io->error('Unexpected response from the database.');

            return Command::FAILURE;
        }

        $io->success('Database connection OK.');

        return Command::SUCCESS;
    }
}
