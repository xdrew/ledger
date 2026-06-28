<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies the env-configured DBAL connection can reach PostgreSQL.
 * Maps to the "Database connectivity" requirement.
 */
final class DatabaseConnectivityTest extends KernelTestCase
{
    #[Test]
    public function connectionExecutesQuery(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        // pdo_pgsql returns integers as numeric strings; compare loosely.
        $value = $connection->executeQuery('SELECT 1')->fetchOne();

        self::assertEquals(1, $value);
    }
}
