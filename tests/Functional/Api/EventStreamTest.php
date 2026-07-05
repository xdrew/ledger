<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * The read-only per-aggregate event endpoints — the showcase's "what was
 * recorded" surface.
 */
final class EventStreamTest extends ApiTestCase
{
    #[Test]
    public function anAccountsHistoryIsReadable(): void
    {
        $accountId = $this->openAccount('USD');
        $this->post(\sprintf('/api/accounts/%s/deposits', $accountId), ['amount' => 5_000, 'currency' => 'USD']);

        $this->get(\sprintf('/api/accounts/%s/events', $accountId));

        self::assertSame(200, $this->statusCode());
        $body = $this->json();
        self::assertSame('account', $body['streamType'] ?? null);
        $events = $body['events'] ?? null;
        self::assertIsArray($events);
        self::assertCount(2, $events);

        $first = $events[0];
        self::assertIsArray($first);
        self::assertSame('accounts.account_opened', $first['type'] ?? null);
        self::assertSame(1, $first['version'] ?? null);
        self::assertNotNull($first['correlationId'] ?? null);
        self::assertIsArray($first['payload'] ?? null);

        $second = $events[1];
        self::assertIsArray($second);
        self::assertSame('accounts.funds_deposited', $second['type'] ?? null);
    }

    #[Test]
    public function aTransferSagaTrailIsReadable(): void
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
        $transferId = $this->json()['transferId'] ?? null;
        self::assertIsString($transferId);

        $this->get(\sprintf('/api/transfers/%s/events', $transferId));

        self::assertSame(200, $this->statusCode());
        $events = $this->json()['events'] ?? null;
        self::assertIsArray($events);
        $types = array_map(static fn(mixed $e): mixed => \is_array($e) ? ($e['type'] ?? null) : null, $events);
        self::assertSame([
            'transfers.transfer_initiated',
            'transfers.transfer_held',
            'transfers.transfer_posted',
            'transfers.transfer_completed',
        ], $types);
    }

    #[Test]
    public function aTransferToAnUnknownDestinationCompensatesVisibly(): void
    {
        $source = $this->openAccount('USD');
        $this->post(\sprintf('/api/accounts/%s/deposits', $source), ['amount' => 5_000, 'currency' => 'USD']);

        $this->post('/api/transfers', [
            'sourceAccountId' => $source,
            'destinationAccountId' => Uuid::uuid7()->toString(),
            'amount' => 1_000,
            'currency' => 'USD',
        ]);

        self::assertSame(201, $this->statusCode());
        $body = $this->json();
        self::assertSame('failed', $body['status'] ?? null);
        self::assertSame('unknown_account', $body['failureReason'] ?? null);

        // The compensation is on the record: hold placed, then released.
        $this->get(\sprintf('/api/accounts/%s/events', $source));
        $events = $this->json()['events'] ?? null;
        self::assertIsArray($events);
        $types = array_map(static fn(mixed $e): mixed => \is_array($e) ? ($e['type'] ?? null) : null, $events);
        self::assertSame([
            'accounts.account_opened',
            'accounts.funds_deposited',
            'accounts.funds_held',
            'accounts.hold_released',
        ], $types);

        // And the money never left.
        $this->catchUpProjections();
        $this->get(\sprintf('/api/accounts/%s', $source));
        self::assertSame(5_000, $this->json()['available'] ?? null);
        self::assertSame(0, $this->json()['reserved'] ?? null);
    }

    #[Test]
    public function unknownStreamsAre404ProblemJson(): void
    {
        $this->get(\sprintf('/api/accounts/%s/events', Uuid::uuid7()->toString()));

        self::assertSame(404, $this->statusCode());
        self::assertStringContainsString('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    #[Test]
    public function eventStreamsRequireTheApiKey(): void
    {
        $this->get('/api/accounts/whatever/events', withKey: false);

        self::assertSame(401, $this->statusCode());
    }
}
