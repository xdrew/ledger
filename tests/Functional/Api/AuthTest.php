<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

final class AuthTest extends ApiTestCase
{
    #[Test]
    public function missingKeyIsRejectedWith401(): void
    {
        $this->post('/api/accounts', ['currency' => 'USD'], withKey: false);

        self::assertSame(401, $this->statusCode());
        self::assertStringContainsString('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(401, $this->json()['status'] ?? null);
    }

    #[Test]
    public function invalidKeyIsRejectedWith401(): void
    {
        $this->client->request(
            'POST',
            '/api/accounts',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_KEY' => 'wrong-key', 'HTTP_IDEMPOTENCY_KEY' => 'k'],
            content: json_encode(['currency' => 'USD'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(401, $this->statusCode());
        self::assertSame(401, $this->json()['status'] ?? null);
    }

    #[Test]
    public function publicEndpointsNeedNoKey(): void
    {
        $this->get('/api/health', withKey: false);
        self::assertSame(200, $this->statusCode());

        $this->get('/api/openapi.json', withKey: false);
        self::assertSame(200, $this->statusCode());
    }
}
