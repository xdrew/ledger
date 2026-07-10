<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Event;

use App\SharedKernel\Event\DomainEvent;

/**
 * Schema v2: adds `account_type` (default "standard"). Rows stored at v1 are
 * brought to this shape by the AccountOpenedV1ToV2 upcaster (infrastructure).
 */
final readonly class AccountOpened implements DomainEvent
{
    use DecodesPayload;
    public const string DEFAULT_ACCOUNT_TYPE = 'standard';

    public function __construct(
        public string $accountId,
        public string $currency,
        public string $accountType = self::DEFAULT_ACCOUNT_TYPE,
    ) {}

    public function toPayload(): array
    {
        return [
            'account_id' => $this->accountId,
            'currency' => $this->currency,
            'account_type' => $this->accountType,
        ];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(
            self::payloadString($payload['account_id'] ?? ''),
            self::payloadString($payload['currency'] ?? ''),
            self::payloadString($payload['account_type'] ?? self::DEFAULT_ACCOUNT_TYPE),
        );
    }
}
