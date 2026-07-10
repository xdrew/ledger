<?php

declare(strict_types=1);

namespace App\Projections\Query;

use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads the ordered `account_statement` read model.
 */
final readonly class AccountStatementView
{
    public function __construct(private Connection $connection) {}

    /**
     * @return list<StatementEntry>
     */
    public function forAccount(string $accountId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT account_id, global_position, entry_type, amount, currency, occurred_at
             FROM account_statement WHERE account_id = :id ORDER BY global_position ASC',
            ['id' => $accountId],
        );

        return array_map(
            static fn(array $row): StatementEntry => new StatementEntry(
                self::asString($row['account_id'] ?? ''),
                self::asInt($row['global_position'] ?? 0),
                self::asString($row['entry_type'] ?? ''),
                Money::of(self::asInt($row['amount'] ?? 0), Currency::of(self::asString($row['currency'] ?? ''))),
                new \DateTimeImmutable(self::asString($row['occurred_at'] ?? 'now')),
            ),
            $rows,
        );
    }

    /**
     * Filtered variant used by natural-language queries: applies the structured
     * filter as bound-parameter SQL and computes the aggregates in the database.
     */
    public function forAccountFiltered(string $accountId, StatementFilter $filter): FilteredStatement
    {
        $where = ['account_id = :id'];
        $params = ['id' => $accountId];

        if ($filter->entryTypes !== null) {
            $where[] = 'entry_type IN (:types)';
            $params['types'] = $filter->entryTypes;
        }
        if ($filter->dateFrom !== null) {
            $where[] = 'occurred_at >= :from';
            $params['from'] = $filter->dateFrom->format('Y-m-d 00:00:00+00');
        }
        if ($filter->dateTo !== null) {
            $where[] = 'occurred_at < :to';
            $params['to'] = $filter->dateTo->modify('+1 day')->format('Y-m-d 00:00:00+00');
        }
        if ($filter->minAmount !== null) {
            $where[] = 'amount >= :min';
            $params['min'] = $filter->minAmount;
        }
        if ($filter->maxAmount !== null) {
            $where[] = 'amount <= :max';
            $params['max'] = $filter->maxAmount;
        }

        $whereSql = implode(' AND ', $where);
        $types = ['types' => ArrayParameterType::STRING];

        $rows = $this->connection->fetchAllAssociative(
            "SELECT account_id, global_position, entry_type, amount, currency, occurred_at
             FROM account_statement WHERE {$whereSql} ORDER BY global_position ASC",
            $params,
            $types,
        );

        $aggregate = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total, MIN(currency) AS currency
             FROM account_statement WHERE {$whereSql}",
            $params,
            $types,
        );

        $entries = array_map(
            static fn(array $row): StatementEntry => new StatementEntry(
                self::asString($row['account_id'] ?? ''),
                self::asInt($row['global_position'] ?? 0),
                self::asString($row['entry_type'] ?? ''),
                Money::of(self::asInt($row['amount'] ?? 0), Currency::of(self::asString($row['currency'] ?? ''))),
                new \DateTimeImmutable(self::asString($row['occurred_at'] ?? 'now')),
            ),
            $rows,
        );

        $currency = \is_array($aggregate) && \is_string($aggregate['currency'] ?? null) ? $aggregate['currency'] : null;

        return new FilteredStatement(
            $entries,
            \is_array($aggregate) ? self::asInt($aggregate['cnt'] ?? 0) : 0,
            \is_array($aggregate) ? self::asInt($aggregate['total'] ?? 0) : 0,
            $currency,
        );
    }

    private static function asString(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function asInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
