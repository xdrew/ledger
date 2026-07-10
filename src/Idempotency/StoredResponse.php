<?php

declare(strict_types=1);

namespace App\Idempotency;

/**
 * The captured response replayed for a duplicate request. Plain data — the HTTP
 * layer maps between this and a real response (no framework coupling here).
 */
final readonly class StoredResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
