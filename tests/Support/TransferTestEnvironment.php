<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Accounts\Domain\Account;
use App\Accounts\Domain\AccountId;
use App\Accounts\Infrastructure\AccountEventTypes;
use App\Accounts\Infrastructure\EventSourcedAccountRepository;
use App\EventStore\EventStore;
use App\EventStore\InMemory\InMemoryEventStore;
use App\EventStore\Serialization\EventTypeRegistry;
use App\Ledger\Domain\JournalPostingService;
use App\Ledger\Infrastructure\AccountRepositoryStatusReader;
use App\Ledger\Infrastructure\EventSourcedLedgerRepository;
use App\Ledger\Infrastructure\LedgerEventTypes;
use App\Observability\Metrics\InMemoryMetrics;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Transfers\Application\TransferOrchestrator;
use App\Transfers\Infrastructure\EventSourcedTransferRepository;
use App\Transfers\Infrastructure\TransferEventTypes;

/**
 * Wires the accounts, ledger and transfers contexts over a shared event store so
 * the transfer saga can be exercised end to end in tests.
 */
final class TransferTestEnvironment
{
    public readonly EventSourcedAccountRepository $accounts;

    public readonly EventSourcedLedgerRepository $ledger;

    public readonly EventSourcedTransferRepository $transfers;

    public readonly TransferOrchestrator $orchestrator;

    public readonly InMemoryMetrics $metrics;

    public function __construct(EventStore $store, EventSourcedAccountRepository $accounts)
    {
        $this->accounts = $accounts;
        $this->ledger = new EventSourcedLedgerRepository($store);
        $this->transfers = new EventSourcedTransferRepository($store);
        $this->metrics = new InMemoryMetrics();
        $posting = new JournalPostingService(new AccountRepositoryStatusReader($accounts));
        $this->orchestrator = new TransferOrchestrator($this->transfers, $accounts, $this->ledger, $posting, $this->metrics);
    }

    public static function registry(): EventTypeRegistry
    {
        $registry = new EventTypeRegistry();
        (new AccountEventTypes())->registerInto($registry);
        (new LedgerEventTypes())->registerInto($registry);
        (new TransferEventTypes())->registerInto($registry);

        return $registry;
    }

    public static function inMemory(): self
    {
        $store = new InMemoryEventStore(new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')), self::registry());

        return new self($store, new EventSourcedAccountRepository($store));
    }

    public function openAccount(int $deposit = 0): AccountId
    {
        $id = AccountId::generate();
        $account = Account::open($id, Currency::of('USD'));
        if ($deposit > 0) {
            $account->deposit(self::usd($deposit));
        }
        $this->accounts->save($account);

        return $id;
    }

    public function closeAccount(AccountId $id): void
    {
        $account = $this->accounts->load($id);
        $account->close();
        $this->accounts->save($account);
    }

    public static function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }
}
