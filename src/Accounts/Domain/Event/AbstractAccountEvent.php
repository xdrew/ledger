<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Event;

use App\SharedKernel\Event\DomainEvent;

/**
 * Base for account events that carry only the account id (status transitions).
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractAccountEvent implements DomainEvent
{
    use DecodesPayload;

    public function __construct(public readonly string $accountId) {}

    final public function toPayload(): array
    {
        return ['account_id' => $this->accountId];
    }

    final public static function fromPayload(array $payload): static
    {
        return new static(self::payloadString($payload['account_id'] ?? ''));
    }
}
