<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Projections\ProjectionRunner;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Base for HTTP API tests: boots a kernel client, ensures the schema exists, and
 * truncates the event store + read models before each test. Tests run on the
 * Symfony kernel (no RoadRunner needed); reads catch projections up explicitly.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const API_KEY = 'local_dev_api_key';

    private static bool $migrated = false;

    protected KernelBrowser $client;

    protected Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        if (!self::$migrated) {
            $application = new Application($this->client->getKernel());
            $tester = new CommandTester($application->find('doctrine:migrations:migrate'));
            $tester->execute(['--no-interaction' => true], ['interactive' => false]);
            $tester->assertCommandIsSuccessful();
            self::$migrated = true;
        }

        $this->connection->executeStatement(
            'TRUNCATE events RESTART IDENTITY; '
            . 'TRUNCATE account_balances, account_statement, projection_checkpoints, consumed_events, idempotency_keys',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function post(string $uri, array $body, ?string $idempotencyKey = 'auto', bool $withKey = true): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($withKey) {
            $server['HTTP_X_API_KEY'] = self::API_KEY;
        }
        if ($idempotencyKey === 'auto') {
            $idempotencyKey = bin2hex(random_bytes(8));
        }
        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $this->client->request('POST', $uri, server: $server, content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function get(string $uri, bool $withKey = true): void
    {
        $server = $withKey ? ['HTTP_X_API_KEY' => self::API_KEY] : [];
        $this->client->request('GET', $uri, server: $server);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    protected function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * Catch the read models up to the latest event so reads are deterministic.
     */
    protected function catchUpProjections(): void
    {
        $runner = self::getContainer()->get(ProjectionRunner::class);
        self::assertInstanceOf(ProjectionRunner::class, $runner);
        $runner->run();
    }

    /**
     * Open an account via the API and return its id. Convenience for arrange steps.
     */
    protected function openAccount(string $currency = 'USD'): string
    {
        $this->post('/api/accounts', ['currency' => $currency]);
        self::assertSame(201, $this->statusCode());

        $id = $this->json()['accountId'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
