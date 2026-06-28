<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Event;

use App\SharedKernel\Event\DomainEvent;

/**
 * Base for account events that carry a monetary amount (account id, minor units,
 * currency code).
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractMoneyEvent implements DomainEvent
{
    use DecodesPayload;

    public function __construct(
        public readonly string $accountId,
        public readonly int $amountMinorUnits,
        public readonly string $currency,
    ) {}

    final public function toPayload(): array
    {
        return [
            'account_id' => $this->accountId,
            'amount' => $this->amountMinorUnits,
            'currency' => $this->currency,
        ];
    }

    final public static function fromPayload(array $payload): static
    {
        return new static(
            self::payloadString($payload['account_id'] ?? ''),
            self::payloadInt($payload['amount'] ?? 0),
            self::payloadString($payload['currency'] ?? ''),
        );
    }
}
