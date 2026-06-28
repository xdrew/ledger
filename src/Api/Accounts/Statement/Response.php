<?php

declare(strict_types=1);

namespace App\Api\Accounts\Statement;

use App\Projections\Query\StatementEntry;

final readonly class Response implements \JsonSerializable
{
    /**
     * @param list<array{globalPosition: int, type: string, amount: int, currency: string, occurredAt: string}> $entries
     */
    public function __construct(
        public string $accountId,
        public array $entries,
    ) {}

    /**
     * @param list<StatementEntry> $entries
     */
    public static function fromEntries(string $accountId, array $entries): self
    {
        return new self(
            $accountId,
            array_map(
                static fn(StatementEntry $entry): array => [
                    'globalPosition' => $entry->globalPosition,
                    'type' => $entry->entryType,
                    'amount' => $entry->amount->minorUnits,
                    'currency' => $entry->amount->currency->code,
                    'occurredAt' => $entry->occurredAt->format(\DateTimeInterface::ATOM),
                ],
                $entries,
            ),
        );
    }

    /**
     * @return array{accountId: string, entries: list<array{globalPosition: int, type: string, amount: int, currency: string, occurredAt: string}>}
     */
    public function jsonSerialize(): array
    {
        return ['accountId' => $this->accountId, 'entries' => $this->entries];
    }
}
