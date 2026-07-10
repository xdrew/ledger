<?php

declare(strict_types=1);

namespace App\Projections\Query;

use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\Connection;

/**
 * Reads the `account_balances` read model.
 */
final readonly class AccountBalanceView
{
    public function __construct(private Connection $connection) {}

    public function find(string $accountId): ?AccountBalance
    {
        $row = $this->connection->fetchAssociative(
            'SELECT account_id, currency, available, reserved, total, version FROM account_balances WHERE account_id = :id',
            ['id' => $accountId],
        );
        if ($row === false) {
            return null;
        }

        $currency = Currency::of(self::asString($row['currency'] ?? ''));

        return new AccountBalance(
            self::asString($row['account_id'] ?? ''),
            Money::of(self::asInt($row['available'] ?? 0), $currency),
            Money::of(self::asInt($row['reserved'] ?? 0), $currency),
            Money::of(self::asInt($row['total'] ?? 0), $currency),
            self::asInt($row['version'] ?? 0),
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
