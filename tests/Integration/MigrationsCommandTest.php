<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies the migrations mechanism is wired: metadata storage can be synced,
 * pending migrations apply, and the schema then reports up-to-date. This is
 * order-independent (migrate is idempotent) and works whether or not other
 * tests have already applied migrations to the shared test database.
 */
final class MigrationsCommandTest extends KernelTestCase
{
    public function testMigrationsApplyAndSchemaReportsUpToDate(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $sync = new CommandTester($application->find('doctrine:migrations:sync-metadata-storage'));
        $sync->execute([], ['interactive' => false]);
        $sync->assertCommandIsSuccessful();

        $migrate = new CommandTester($application->find('doctrine:migrations:migrate'));
        $migrate->execute(['--no-interaction' => true], ['interactive' => false]);
        $migrate->assertCommandIsSuccessful();

        $upToDate = new CommandTester($application->find('doctrine:migrations:up-to-date'));
        $upToDate->execute([], ['interactive' => false]);
        $upToDate->assertCommandIsSuccessful();
    }
}
