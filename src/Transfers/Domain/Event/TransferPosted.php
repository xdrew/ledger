<?php

declare(strict_types=1);

namespace App\Transfers\Domain\Event;

use App\SharedKernel\Event\DomainEvent;

final class TransferPosted implements DomainEvent
{
    use DecodesPayload;

    public function __construct(
        public readonly string $transferId,
        public readonly string $journalEntryId,
    ) {}

    public function toPayload(): array
    {
        return ['transfer_id' => $this->transferId, 'journal_entry_id' => $this->journalEntryId];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(self::str($payload['transfer_id'] ?? ''), self::str($payload['journal_entry_id'] ?? ''));
    }
}
