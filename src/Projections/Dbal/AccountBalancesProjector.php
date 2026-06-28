<?php

declare(strict_types=1);

namespace App\Projections\Dbal;

use App\Accounts\Domain\Event\AbstractMoneyEvent;
use App\Accounts\Domain\Event\AccountOpened;
use App\Accounts\Domain\Event\FundsCredited;
use App\Accounts\Domain\Event\FundsDebited;
use App\Accounts\Domain\Event\FundsDeposited;
use App\Accounts\Domain\Event\FundsHeld;
use App\Accounts\Domain\Event\HoldReleased;
use App\EventStore\RecordedEvent;
use App\Projections\Projector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Projects account events into the `account_balances` read model.
 */
final class AccountBalancesProjector implements Projector
{
    public function __construct(private readonly Connection $connection) {}

    public function handles(RecordedEvent $event): bool
    {
        return $event->event instanceof AccountOpened || $event->event instanceof AbstractMoneyEvent;
    }

    public function project(RecordedEvent $event): void
    {
        $domainEvent = $event->event;

        if ($domainEvent instanceof AccountOpened) {
            $this->connection->executeStatement(
                'INSERT INTO account_balances (account_id, currency, available, reserved, total, version, updated_at)
                 VALUES (:id, :currency, 0, 0, 0, :version, now())
                 ON CONFLICT (account_id) DO NOTHING',
                ['id' => $domainEvent->accountId, 'currency' => $domainEvent->currency, 'version' => $event->version],
                ['version' => ParameterType::INTEGER],
            );

            return;
        }

        if (!$domainEvent instanceof AbstractMoneyEvent) {
            return;
        }

        $amount = $domainEvent->amountMinorUnits;
        [$availableDelta, $reservedDelta, $totalDelta] = match ($domainEvent::class) {
            FundsDeposited::class => [$amount, 0, $amount],
            FundsHeld::class => [-$amount, $amount, 0],
            HoldReleased::class => [$amount, -$amount, 0],
            FundsDebited::class => [0, -$amount, -$amount],
            FundsCredited::class => [$amount, 0, $amount],
            default => [0, 0, 0],
        };

        $this->connection->executeStatement(
            'UPDATE account_balances
             SET available = available + :da, reserved = reserved + :dr, total = total + :dt,
                 version = :version, updated_at = now()
             WHERE account_id = :id',
            [
                'da' => $availableDelta,
                'dr' => $reservedDelta,
                'dt' => $totalDelta,
                'version' => $event->version,
                'id' => $domainEvent->accountId,
            ],
            [
                'da' => ParameterType::INTEGER,
                'dr' => ParameterType::INTEGER,
                'dt' => ParameterType::INTEGER,
                'version' => ParameterType::INTEGER,
            ],
        );
    }
}
