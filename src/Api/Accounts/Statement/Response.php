<?php

declare(strict_types=1);

namespace App\Api\Accounts\Statement;

use App\Projections\Query\FilteredStatement;
use App\Projections\Query\StatementEntry;
use App\Projections\Query\StatementFilter;

final readonly class Response implements \JsonSerializable
{
    /**
     * @param list<array{globalPosition: int, type: string, amount: int, currency: string, occurredAt: string}> $entries
     * @param array<string, mixed>|null $interpretation the applied filter (NL queries only)
     * @param array<string, mixed>|null $aggregate SQL-computed sum/count (NL queries only)
     */
    public function __construct(
        public string $accountId,
        public array $entries,
        public ?array $interpretation = null,
        public ?array $aggregate = null,
    ) {}

    /**
     * @param list<StatementEntry> $entries
     */
    public static function fromEntries(string $accountId, array $entries): self
    {
        return new self($accountId, self::mapEntries($entries));
    }

    public static function fromFiltered(string $accountId, FilteredStatement $result, StatementFilter $filter): self
    {
        $aggregate = match ($filter->aggregation) {
            'sum' => ['type' => 'sum', 'value' => $result->sumMinorUnits, 'currency' => $result->currency, 'count' => $result->count],
            'count' => ['type' => 'count', 'value' => $result->count],
            default => null,
        };

        return new self($accountId, self::mapEntries($result->entries), $filter->toArray(), $aggregate);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $body = ['accountId' => $this->accountId, 'entries' => $this->entries];
        if ($this->interpretation !== null) {
            $body['interpretation'] = $this->interpretation;
        }
        if ($this->aggregate !== null) {
            $body['aggregate'] = $this->aggregate;
        }

        return $body;
    }

    /**
     * @param list<StatementEntry> $entries
     * @return list<array{globalPosition: int, type: string, amount: int, currency: string, occurredAt: string}>
     */
    private static function mapEntries(array $entries): array
    {
        return array_map(
            static fn(StatementEntry $entry): array => [
                'globalPosition' => $entry->globalPosition,
                'type' => $entry->entryType,
                'amount' => $entry->amount->minorUnits,
                'currency' => $entry->amount->currency->code,
                'occurredAt' => $entry->occurredAt->format(\DateTimeInterface::ATOM),
            ],
            $entries,
        );
    }
}
