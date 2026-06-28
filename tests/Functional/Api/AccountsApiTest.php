<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class AccountsApiTest extends ApiTestCase
{
    #[Test]
    public function openAccountReturns201WithId(): void
    {
        $this->post('/api/accounts', ['currency' => 'USD']);

        self::assertSame(201, $this->statusCode());
        $body = $this->json();
        self::assertSame('USD', $body['currency'] ?? null);
        $accountId = $body['accountId'] ?? null;
        self::assertIsString($accountId);
        self::assertTrue(Uuid::isValid($accountId));
    }

    #[Test]
    public function depositThenBalanceReflectsIt(): void
    {
        $accountId = $this->openAccount('USD');

        $this->post(\sprintf('/api/accounts/%s/deposits', $accountId), ['amount' => 10_000, 'currency' => 'USD']);
        self::assertSame(200, $this->statusCode());

        $this->catchUpProjections();

        $this->get(\sprintf('/api/accounts/%s', $accountId));
        self::assertSame(200, $this->statusCode());

        $balance = $this->json();
        self::assertSame(10_000, $balance['available'] ?? null);
        self::assertSame(10_000, $balance['total'] ?? null);
        self::assertSame(0, $balance['reserved'] ?? null);
        self::assertSame('USD', $balance['currency'] ?? null);
    }

    #[Test]
    public function unknownAccountReturns404ProblemJson(): void
    {
        $this->get(\sprintf('/api/accounts/%s', Uuid::uuid7()->toString()));

        self::assertSame(404, $this->statusCode());
        self::assertStringContainsString('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(404, $this->json()['status'] ?? null);
    }

    #[Test]
    public function statementListsActivityInOrder(): void
    {
        $accountId = $this->openAccount('USD');
        $this->post(\sprintf('/api/accounts/%s/deposits', $accountId), ['amount' => 2_500, 'currency' => 'USD']);
        $this->catchUpProjections();

        $this->get(\sprintf('/api/accounts/%s/statement', $accountId));
        self::assertSame(200, $this->statusCode());

        $body = $this->json();
        self::assertSame($accountId, $body['accountId'] ?? null);
        $entries = $body['entries'] ?? null;
        self::assertIsArray($entries);
        self::assertNotEmpty($entries);
    }
}
