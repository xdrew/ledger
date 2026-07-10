<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messaging;

use App\Accounts\Application\DepositFunds;
use App\Accounts\Application\DepositFundsHandler;
use App\Accounts\Application\OpenAccount;
use App\Accounts\Application\OpenAccountHandler;
use App\Accounts\Domain\AccountId;
use App\Accounts\Infrastructure\AccountEventTypes;
use App\Accounts\Infrastructure\EventSourcedAccountRepository;
use App\EventStore\InMemory\InMemoryEventStore;
use App\EventStore\Serialization\EventTypeRegistry;
use App\EventStore\StreamId;
use App\Ledger\Domain\JournalPostingService;
use App\Ledger\Infrastructure\AccountRepositoryStatusReader;
use App\Ledger\Infrastructure\EventSourcedLedgerRepository;
use App\Ledger\Infrastructure\LedgerEventTypes;
use App\Messaging\CommandBus;
use App\Observability\Metrics\NullMetrics;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Tests\Support\FixedClock;
use App\Transfers\Application\InitiateTransfer;
use App\Transfers\Application\InitiateTransferHandler;
use App\Transfers\Application\TransferOrchestrator;
use App\Transfers\Domain\TransferId;
use App\Transfers\Infrastructure\EventSourcedTransferRepository;
use App\Transfers\Infrastructure\TransferEventTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thesis\MessageBus\Exception\NoHandler;

final class CommandBusTest extends TestCase
{
    private InMemoryEventStore $store;

    private EventSourcedAccountRepository $accounts;

    private EventSourcedTransferRepository $transfers;

    private CommandBus $bus;

    protected function setUp(): void
    {
        $registry = new EventTypeRegistry();
        new AccountEventTypes()->registerInto($registry);
        new LedgerEventTypes()->registerInto($registry);
        new TransferEventTypes()->registerInto($registry);

        $clock = new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->store = new InMemoryEventStore($clock, $registry);
        $this->accounts = new EventSourcedAccountRepository($this->store);
        $this->transfers = new EventSourcedTransferRepository($this->store);
        $ledger = new EventSourcedLedgerRepository($this->store);
        $orchestrator = new TransferOrchestrator(
            $this->transfers,
            $this->accounts,
            $ledger,
            new JournalPostingService(new AccountRepositoryStatusReader($this->accounts)),
            new NullMetrics(),
        );

        $this->bus = new CommandBus(
            new OpenAccountHandler($this->accounts),
            new DepositFundsHandler($this->accounts),
            new InitiateTransferHandler($orchestrator),
            $clock,
        );
    }

    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    #[Test]
    public function openThenDepositViaCommandsUpdatesTheBalance(): void
    {
        $id = AccountId::generate();
        $this->bus->dispatch(new OpenAccount($id, Currency::of('USD')));
        $this->bus->dispatch(new DepositFunds($id, $this->usd(10_000)));

        self::assertTrue($this->accounts->load($id)->availableBalance()->equals($this->usd(10_000)));
    }

    #[Test]
    public function anUnregisteredCommandRaisesNoHandler(): void
    {
        $this->expectException(NoHandler::class);
        $this->bus->dispatch(new \stdClass());
    }

    #[Test]
    public function initiateTransferViaCommandCompletesTheTransfer(): void
    {
        $source = AccountId::generate();
        $destination = AccountId::generate();
        $this->bus->dispatch(new OpenAccount($source, Currency::of('USD')));
        $this->bus->dispatch(new DepositFunds($source, $this->usd(10_000)));
        $this->bus->dispatch(new OpenAccount($destination, Currency::of('USD')));

        $transferId = TransferId::generate();
        $this->bus->dispatch(new InitiateTransfer($transferId, $source, $destination, $this->usd(10_000)));

        self::assertTrue($this->transfers->load($transferId)->isCompleted());
    }

    #[Test]
    public function correlationIdPropagatesToRecordedEvents(): void
    {
        $id = AccountId::generate();
        $this->bus->dispatch(new OpenAccount($id, Currency::of('USD')), 'corr-1');

        $events = $this->store->load(StreamId::of('account', $id->toString()));
        self::assertNotEmpty($events);
        self::assertSame('corr-1', $events[0]->metadata->correlationId);
        self::assertNotNull($events[0]->metadata->causationId, 'The command message id is recorded as causation.');
    }
}
