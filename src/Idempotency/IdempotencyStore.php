<?php

declare(strict_types=1);

namespace App\Idempotency;

use App\Idempotency\Outcome\BeginOutcome;

/**
 * Deduplication store for mutating requests, keyed by (idempotency key, route).
 */
interface IdempotencyStore
{
    /**
     * Reservations older than this are considered abandoned (their owner crashed
     * before completing or releasing) and may be reclaimed by a retry. Must
     * comfortably exceed the longest possible request.
     */
    public const STALE_IN_PROGRESS_SECONDS = 300;

    /**
     * Reserve a key for processing, or report why it cannot be processed again.
     */
    public function begin(IdempotencyKey $key, string $route, string $requestHash): BeginOutcome;

    /**
     * Store the response for a reserved key and mark it completed (with a TTL).
     */
    public function complete(IdempotencyKey $key, string $route, StoredResponse $response): void;

    /**
     * Drop an unfinished reservation so the same key can be retried immediately.
     * Used when the attempt failed server-side and produced nothing to replay;
     * a no-op once the key has completed.
     */
    public function release(IdempotencyKey $key, string $route): void;
}
