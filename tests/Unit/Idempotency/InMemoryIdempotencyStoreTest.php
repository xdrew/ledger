<?php

declare(strict_types=1);

namespace App\Tests\Unit\Idempotency;

use App\Idempotency\IdempotencyKey;
use App\Idempotency\InMemory\InMemoryIdempotencyStore;
use App\Idempotency\Outcome\Begun;
use App\Idempotency\Outcome\Completed;
use App\Idempotency\Outcome\InProgress;
use App\Idempotency\Outcome\Mismatch;
use App\Idempotency\StoredResponse;
use App\Idempotency\Ttl;
use App\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryIdempotencyStoreTest extends TestCase
{
    private const ROUTE = 'POST /transfers';

    private FixedClock $clock;

    private InMemoryIdempotencyStore $store;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->store = new InMemoryIdempotencyStore($this->clock, Ttl::ofSeconds(60));
    }

    private function key(string $value = 'key-1'): IdempotencyKey
    {
        return IdempotencyKey::fromString($value);
    }

    #[Test]
    public function freshKeyIsBegun(): void
    {
        self::assertInstanceOf(Begun::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }

    #[Test]
    public function completedKeyReplaysTheStoredResponse(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');
        $this->store->complete($this->key(), self::ROUTE, new StoredResponse(201, ['Content-Type' => 'application/json'], '{"id":1}'));

        $outcome = $this->store->begin($this->key(), self::ROUTE, 'hash-1');

        self::assertInstanceOf(Completed::class, $outcome);
        self::assertSame(201, $outcome->response->status);
        self::assertSame(['Content-Type' => 'application/json'], $outcome->response->headers);
        self::assertSame('{"id":1}', $outcome->response->body);
    }

    #[Test]
    public function inFlightKeyIsInProgress(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');

        self::assertInstanceOf(InProgress::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }

    #[Test]
    public function reusedKeyWithDifferentPayloadIsMismatch(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');

        self::assertInstanceOf(Mismatch::class, $this->store->begin($this->key(), self::ROUTE, 'hash-2'));
    }

    #[Test]
    public function expiredCompletedKeyIsReclaimed(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');
        $this->store->complete($this->key(), self::ROUTE, new StoredResponse(200, [], 'ok'));

        $this->clock->set(new \DateTimeImmutable('2026-01-01T00:02:00+00:00')); // +120s > 60s TTL

        self::assertInstanceOf(Begun::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }

    #[Test]
    public function releasedKeyCanBeRetriedImmediately(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');

        $this->store->release($this->key(), self::ROUTE);

        self::assertInstanceOf(Begun::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }

    #[Test]
    public function releasingACompletedKeyKeepsTheStoredResponse(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');
        $this->store->complete($this->key(), self::ROUTE, new StoredResponse(200, [], 'ok'));

        $this->store->release($this->key(), self::ROUTE);

        self::assertInstanceOf(Completed::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }

    #[Test]
    public function staleInProgressKeyIsReclaimed(): void
    {
        $this->store->begin($this->key(), self::ROUTE, 'hash-1');

        $this->clock->set(new \DateTimeImmutable('2026-01-01T00:06:00+00:00')); // +360s > 300s staleness

        self::assertInstanceOf(Begun::class, $this->store->begin($this->key(), self::ROUTE, 'hash-1'));
    }
}
