<?php

declare(strict_types=1);

namespace App\Projections\Query;

/**
 * A structured filter over the `account_statement` read model — the contract
 * between the NL translator and SQL execution. Everything a natural-language
 * question can ask for must be expressible here; anything not expressible here
 * cannot reach the database. Validated on construction: unknown entry types and
 * inverted ranges are rejected, so a schema-valid-but-wrong translation still
 * cannot produce malformed SQL input.
 */
final class StatementFilter
{
    public const ENTRY_TYPES = ['deposit', 'hold', 'hold_release', 'debit', 'credit'];
    public const AGGREGATIONS = ['list', 'sum', 'count'];

    /**
     * @param list<string>|null $entryTypes null = all types
     */
    private function __construct(
        public readonly ?array $entryTypes,
        public readonly ?\DateTimeImmutable $dateFrom,
        public readonly ?\DateTimeImmutable $dateTo,
        public readonly ?int $minAmount,
        public readonly ?int $maxAmount,
        public readonly string $aggregation,
    ) {}

    public static function all(): self
    {
        return new self(null, null, null, null, null, 'list');
    }

    /**
     * Builds a filter from the translator's decoded payload, rejecting anything
     * outside the contract.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $entryTypes = null;
        if (isset($payload['entry_types'])) {
            if (!\is_array($payload['entry_types'])) {
                throw new \InvalidArgumentException('entry_types must be a list or null.');
            }
            $entryTypes = [];
            foreach ($payload['entry_types'] as $type) {
                if (!\is_string($type) || !\in_array($type, self::ENTRY_TYPES, true)) {
                    throw new \InvalidArgumentException(\sprintf('Unknown entry type "%s".', \is_scalar($type) ? (string) $type : \gettype($type)));
                }
                $entryTypes[] = $type;
            }
            if ($entryTypes === []) {
                $entryTypes = null;
            }
        }

        $dateFrom = self::date($payload['date_from'] ?? null, 'date_from');
        $dateTo = self::date($payload['date_to'] ?? null, 'date_to');
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new \InvalidArgumentException('date_from must not be after date_to.');
        }

        $minAmount = self::amount($payload['min_amount'] ?? null, 'min_amount');
        $maxAmount = self::amount($payload['max_amount'] ?? null, 'max_amount');
        if ($minAmount !== null && $maxAmount !== null && $minAmount > $maxAmount) {
            throw new \InvalidArgumentException('min_amount must not exceed max_amount.');
        }

        $aggregation = $payload['aggregation'] ?? 'list';
        if (!\is_string($aggregation) || !\in_array($aggregation, self::AGGREGATIONS, true)) {
            throw new \InvalidArgumentException('aggregation must be one of list, sum, count.');
        }

        return new self($entryTypes, $dateFrom, $dateTo, $minAmount, $maxAmount, $aggregation);
    }

    /**
     * The filter as applied — echoed to callers so they see how their question
     * was understood.
     *
     * @return array{entry_types: list<string>|null, date_from: string|null, date_to: string|null, min_amount: int|null, max_amount: int|null, aggregation: string}
     */
    public function toArray(): array
    {
        return [
            'entry_types' => $this->entryTypes,
            'date_from' => $this->dateFrom?->format('Y-m-d'),
            'date_to' => $this->dateTo?->format('Y-m-d'),
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'aggregation' => $this->aggregation,
        ];
    }

    private static function date(mixed $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('%s must be a date string or null.', $field));
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            throw new \InvalidArgumentException(\sprintf('%s is not a valid Y-m-d date.', $field));
        }

        return $date;
    }

    private static function amount(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!\is_int($value) || $value < 0) {
            throw new \InvalidArgumentException(\sprintf('%s must be a non-negative integer (minor units) or null.', $field));
        }

        return $value;
    }
}
