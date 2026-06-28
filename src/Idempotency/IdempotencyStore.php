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
     * Reserve a key for processing, or report why it cannot be processed again.
     */
    public function begin(IdempotencyKey $key, string $route, string $requestHash): BeginOutcome;

    /**
     * Store the response for a reserved key and mark it completed (with a TTL).
     */
    public function complete(IdempotencyKey $key, string $route, StoredResponse $response): void;
}
