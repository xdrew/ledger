<?php

declare(strict_types=1);

namespace App\Transfers\Domain\Event;

use App\SharedKernel\Event\DomainEvent;

final readonly class TransferInitiated implements DomainEvent
{
    use DecodesPayload;

    public function __construct(
        public string $transferId,
        public string $sourceAccountId,
        public string $destinationAccountId,
        public int $amountMinorUnits,
        public string $currency,
        public ?string $reversalOf,
    ) {}

    public function toPayload(): array
    {
        return [
            'transfer_id' => $this->transferId,
            'source_account_id' => $this->sourceAccountId,
            'destination_account_id' => $this->destinationAccountId,
            'amount' => $this->amountMinorUnits,
            'currency' => $this->currency,
            'reversal_of' => $this->reversalOf,
        ];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(
            self::str($payload['transfer_id'] ?? ''),
            self::str($payload['source_account_id'] ?? ''),
            self::str($payload['destination_account_id'] ?? ''),
            self::int($payload['amount'] ?? 0),
            self::str($payload['currency'] ?? ''),
            self::nullableStr($payload['reversal_of'] ?? null),
        );
    }
}
