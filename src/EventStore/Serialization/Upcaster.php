<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

/**
 * A single-step, pure payload transformation for one event type: takes a payload
 * written at {@see fromVersion()} and returns it at fromVersion()+1. Chained by
 * {@see UpcasterChain} to bring stored events to the current shape at read time —
 * stored payloads are never rewritten (ADR-006). N schema versions need N-1
 * upcasters; each must be total for its input version and must not invent facts
 * (only derivable defaults).
 */
interface Upcaster
{
    /**
     * The stable event type string this upcaster applies to (e.g. "accounts.account_opened").
     */
    public function eventType(): string;

    /**
     * The stored schema version this upcaster consumes (producing fromVersion()+1).
     */
    public function fromVersion(): int;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload): array;
}
