<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class ValidationTest extends ApiTestCase
{
    #[Test]
    public function invalidCurrencyIsRejectedWith422ProblemJson(): void
    {
        $this->post('/api/accounts', ['currency' => 'usd']);

        self::assertSame(422, $this->statusCode());
        self::assertStringContainsString('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $body = $this->json();
        self::assertSame(422, $body['status'] ?? null);
        $errors = $body['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('currency', $first['field'] ?? null);
    }

    #[Test]
    public function nonPositiveDepositAmountIsRejectedWith422(): void
    {
        $accountId = $this->openAccount('USD');

        $this->post(\sprintf('/api/accounts/%s/deposits', $accountId), ['amount' => 0, 'currency' => 'USD']);

        self::assertSame(422, $this->statusCode());
        $errors = $this->json()['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('amount', $first['field'] ?? null);
    }

    #[Test]
    public function malformedUuidInTransferIsRejectedWith422(): void
    {
        $this->post('/api/transfers', [
            'sourceAccountId' => 'not-a-uuid',
            'destinationAccountId' => Uuid::uuid7()->toString(),
            'amount' => 100,
            'currency' => 'USD',
        ]);

        self::assertSame(422, $this->statusCode());
    }
}
