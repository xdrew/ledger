<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class TransfersApiTest extends ApiTestCase
{
    #[Test]
    public function aFundedTransferCompletes(): void
    {
        $source = $this->openAccount('USD');
        $destination = $this->openAccount('USD');
        $this->post(\sprintf('/api/accounts/%s/deposits', $source), ['amount' => 10_000, 'currency' => 'USD']);

        $this->post('/api/transfers', [
            'sourceAccountId' => $source,
            'destinationAccountId' => $destination,
            'amount' => 10_000,
            'currency' => 'USD',
        ]);

        self::assertSame(201, $this->statusCode());
        $body = $this->json();
        self::assertSame('completed', $body['status'] ?? null);
        self::assertArrayHasKey('failureReason', $body);
        self::assertNull($body['failureReason']);
        $transferId = $body['transferId'] ?? null;
        self::assertIsString($transferId);
        self::assertTrue(Uuid::isValid($transferId));
    }

    #[Test]
    public function anUnderfundedTransferIsReportedAsFailed(): void
    {
        $source = $this->openAccount('USD');
        $destination = $this->openAccount('USD');

        $this->post('/api/transfers', [
            'sourceAccountId' => $source,
            'destinationAccountId' => $destination,
            'amount' => 10_000,
            'currency' => 'USD',
        ]);

        // A business failure is still a successful request.
        self::assertSame(201, $this->statusCode());
        $body = $this->json();
        self::assertSame('failed', $body['status'] ?? null);
        self::assertSame('insufficient_funds', $body['failureReason'] ?? null);
    }

    #[Test]
    public function readingATransferReturnsItsStatus(): void
    {
        $source = $this->openAccount('USD');
        $destination = $this->openAccount('USD');
        $this->post(\sprintf('/api/accounts/%s/deposits', $source), ['amount' => 5_000, 'currency' => 'USD']);
        $this->post('/api/transfers', [
            'sourceAccountId' => $source,
            'destinationAccountId' => $destination,
            'amount' => 5_000,
            'currency' => 'USD',
        ]);
        $transferId = $this->json()['transferId'] ?? null;
        self::assertIsString($transferId);

        $this->get(\sprintf('/api/transfers/%s', $transferId));
        self::assertSame(200, $this->statusCode());
        $body = $this->json();
        self::assertSame($transferId, $body['transferId'] ?? null);
        self::assertSame('completed', $body['status'] ?? null);
        self::assertSame(5_000, $body['amount'] ?? null);
    }

    #[Test]
    public function unknownTransferReturns404(): void
    {
        $this->get(\sprintf('/api/transfers/%s', Uuid::uuid7()->toString()));

        self::assertSame(404, $this->statusCode());
        self::assertSame(404, $this->json()['status'] ?? null);
    }
}
