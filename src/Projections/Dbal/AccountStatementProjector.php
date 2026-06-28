<?php

declare(strict_types=1);

namespace App\Projections\Dbal;

use App\Accounts\Domain\Event\AbstractMoneyEvent;
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
 * Projects account postings/holds into the ordered `account_statement` read model.
 */
final class AccountStatementProjector implements Projector
{
    public function __construct(private readonly Connection $connection) {}

    public function handles(RecordedEvent $event): bool
    {
        return $event->event instanceof AbstractMoneyEvent;
    }

    public function project(RecordedEvent $event): void
    {
        $domainEvent = $event->event;
        if (!$domainEvent instanceof AbstractMoneyEvent) {
            return;
        }

        $entryType = match ($domainEvent::class) {
            FundsDeposited::class => 'deposit',
            FundsHeld::class => 'hold',
            HoldReleased::class => 'hold_released',
            FundsDebited::class => 'debit',
            FundsCredited::class => 'credit',
            default => 'unknown',
        };

        $this->connection->executeStatement(
            'INSERT INTO account_statement (account_id, global_position, entry_type, amount, currency, occurred_at)
             VALUES (:id, :position, :type, :amount, :currency, :occurred_at)
             ON CONFLICT (global_position) DO NOTHING',
            [
                'id' => $domainEvent->accountId,
                'position' => $event->globalPosition ?? 0,
                'type' => $entryType,
                'amount' => $domainEvent->amountMinorUnits,
                'currency' => $domainEvent->currency,
                'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s.uP'),
            ],
            [
                'position' => ParameterType::INTEGER,
                'amount' => ParameterType::INTEGER,
            ],
        );
    }
}
