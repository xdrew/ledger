<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Outbox\Dbal\RelayCheckpoint;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HealthProbesTest extends ApiTestCase
{
    #[Test]
    public function healthzIsAliveWithoutAKey(): void
    {
        $this->get('/healthz', withKey: false);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['status' => 'alive'], $this->json());
    }

    #[Test]
    public function readyzIsNotReadyWhenTheRelayHasNoHeartbeat(): void
    {
        // setUp truncated the checkpoints, so the relay has never beaten.
        $this->get('/readyz', withKey: false);

        self::assertSame(503, $this->statusCode());
        $body = $this->json();
        self::assertSame('not_ready', $body['status'] ?? null);
        $checks = $body['checks'] ?? null;
        self::assertIsArray($checks);
        self::assertSame('ok', $checks['database'] ?? null);
        self::assertNotSame('ok', $checks['outbox_relay'] ?? null);
    }

    #[Test]
    public function readyzIsReadyAfterAFreshRelayHeartbeat(): void
    {
        $relay = self::getContainer()->get(RelayCheckpoint::class);
        self::assertInstanceOf(RelayCheckpoint::class, $relay);
        $relay->touch();

        $this->get('/readyz', withKey: false);

        self::assertSame(200, $this->statusCode());
        $body = $this->json();
        self::assertSame('ready', $body['status'] ?? null);
    }
}
