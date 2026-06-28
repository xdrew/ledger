<?php

declare(strict_types=1);

namespace App\Idempotency\InMemory;

use App\Idempotency\IdempotencyKey;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\Outcome\BeginOutcome;
use App\Idempotency\Outcome\Begun;
use App\Idempotency\Outcome\Completed;
use App\Idempotency\Outcome\InProgress;
use App\Idempotency\Outcome\Mismatch;
use App\Idempotency\StoredResponse;
use App\Idempotency\Ttl;
use App\SharedKernel\Clock\Clock;

/**
 * In-memory idempotency store for unit tests of the classification rules
 * (begin / replay / in-progress / mismatch / TTL reclaim). Single-process, so it
 * does not model the DB-level concurrency guarantee — that is integration-tested.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /**
     * @var array<string, array{hash: string, status: string, response: ?StoredResponse, expiresAt: ?\DateTimeImmutable}>
     */
    private array $records = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly Ttl $ttl,
    ) {}

    public function begin(IdempotencyKey $key, string $route, string $requestHash): BeginOutcome
    {
        $id = $this->id($key, $route);
        $now = $this->clock->now();
        $record = $this->records[$id] ?? null;

        $expired = $record !== null
            && $record['status'] === 'completed'
            && $record['expiresAt'] !== null
            && $record['expiresAt'] < $now;

        if ($record === null || $expired) {
            $this->records[$id] = ['hash' => $requestHash, 'status' => 'in_progress', 'response' => null, 'expiresAt' => null];

            return new Begun();
        }

        if ($record['hash'] !== $requestHash) {
            return new Mismatch();
        }

        if ($record['status'] === 'in_progress') {
            return new InProgress();
        }

        return new Completed($record['response'] ?? new StoredResponse(0, [], ''));
    }

    public function complete(IdempotencyKey $key, string $route, StoredResponse $response): void
    {
        $id = $this->id($key, $route);
        $record = $this->records[$id] ?? null;
        if ($record === null) {
            return;
        }

        $record['status'] = 'completed';
        $record['response'] = $response;
        $record['expiresAt'] = $this->ttl->expiresFrom($this->clock->now());
        $this->records[$id] = $record;
    }

    private function id(IdempotencyKey $key, string $route): string
    {
        return $key->value . '|' . $route;
    }
}
