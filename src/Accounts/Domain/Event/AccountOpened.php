<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Event;

use App\SharedKernel\Event\DomainEvent;

final class AccountOpened implements DomainEvent
{
    use DecodesPayload;

    public function __construct(
        public readonly string $accountId,
        public readonly string $currency,
    ) {}

    public function toPayload(): array
    {
        return ['account_id' => $this->accountId, 'currency' => $this->currency];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(
            self::payloadString($payload['account_id'] ?? ''),
            self::payloadString($payload['currency'] ?? ''),
        );
    }
}
