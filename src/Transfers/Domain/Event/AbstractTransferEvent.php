<?php

declare(strict_types=1);

namespace App\Transfers\Domain\Event;

use App\SharedKernel\Event\DomainEvent;

/**
 * Base for transfer events that carry only the transfer id.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractTransferEvent implements DomainEvent
{
    use DecodesPayload;

    public function __construct(public readonly string $transferId) {}

    final public function toPayload(): array
    {
        return ['transfer_id' => $this->transferId];
    }

    final public static function fromPayload(array $payload): static
    {
        return new static(self::str($payload['transfer_id'] ?? ''));
    }
}
