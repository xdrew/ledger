<?php

declare(strict_types=1);

namespace App\Transfers\Domain\Event;

use App\SharedKernel\Event\DomainEvent;

final readonly class TransferFailed implements DomainEvent
{
    use DecodesPayload;

    public function __construct(
        public string $transferId,
        public string $reason,
    ) {}

    public function toPayload(): array
    {
        return ['transfer_id' => $this->transferId, 'reason' => $this->reason];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(self::str($payload['transfer_id'] ?? ''), self::str($payload['reason'] ?? ''));
    }
}
