<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Idempotency\IdempotencyKey;
use App\Idempotency\IdempotencyStore;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

final class IdempotencyTest extends ApiTestCase
{
    #[Test]
    public function replayingACompletedKeyReturnsTheStoredResponse(): void
    {
        $this->post('/api/accounts', ['currency' => 'USD'], idempotencyKey: 'replay-1');
        self::assertSame(201, $this->statusCode());
        $first = $this->json();

        $this->post('/api/accounts', ['currency' => 'USD'], idempotencyKey: 'replay-1');
        self::assertSame(201, $this->statusCode());
        $second = $this->json();

        // Same response (same generated id) proves the handler did not run twice.
        self::assertSame($first, $second);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotent-Replayed'));
    }

    #[Test]
    public function reusingAKeyWithADifferentPayloadIsRejectedWith422(): void
    {
        $this->post('/api/accounts', ['currency' => 'USD'], idempotencyKey: 'mismatch-1');
        self::assertSame(201, $this->statusCode());

        $this->post('/api/accounts', ['currency' => 'EUR'], idempotencyKey: 'mismatch-1');
        self::assertSame(422, $this->statusCode());
        self::assertSame(422, $this->json()['status'] ?? null);
    }

    #[Test]
    public function aMissingIdempotencyKeyIsRejectedWith400(): void
    {
        $this->post('/api/accounts', ['currency' => 'USD'], idempotencyKey: null);

        self::assertSame(400, $this->statusCode());
        self::assertSame(400, $this->json()['status'] ?? null);
    }

    #[Test]
    public function anInFlightKeyIsRejectedWith409(): void
    {
        $store = self::getContainer()->get(IdempotencyStore::class);
        self::assertInstanceOf(IdempotencyStore::class, $store);

        $body = ['currency' => 'USD'];
        $content = json_encode($body, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', 'POST /api/accounts ' . $content);

        // Reserve the key without completing it: a concurrent request is in flight.
        $store->begin(IdempotencyKey::fromString('inflight-1'), 'api_accounts_open', $hash);

        $this->post('/api/accounts', $body, idempotencyKey: 'inflight-1');

        self::assertSame(409, $this->statusCode());
        self::assertSame(409, $this->json()['status'] ?? null);
    }
}
