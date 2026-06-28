<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\SharedKernel\Event\DomainEvent;

/**
 * A trivial domain event used to exercise the event store and aggregate root.
 */
final class SomethingHappened implements DomainEvent
{
    public const TYPE = 'test.something_happened';

    public function __construct(
        public readonly string $what,
        public readonly int $amount,
    ) {}

    public function toPayload(): array
    {
        return ['what' => $this->what, 'amount' => $this->amount];
    }

    public static function fromPayload(array $payload): self
    {
        $what = $payload['what'] ?? '';
        $amount = $payload['amount'] ?? 0;

        return new self(
            \is_string($what) ? $what : '',
            \is_int($amount) ? $amount : (is_numeric($amount) ? (int) $amount : 0),
        );
    }
}
