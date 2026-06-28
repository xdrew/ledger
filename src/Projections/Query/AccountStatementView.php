<?php

declare(strict_types=1);

namespace App\Projections\Query;

use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\Connection;

/**
 * Reads the ordered `account_statement` read model.
 */
final class AccountStatementView
{
    public function __construct(private readonly Connection $connection) {}

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
