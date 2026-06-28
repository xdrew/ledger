<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transfers;

use App\Accounts\Domain\Account;
use App\Accounts\Domain\AccountId;
use App\Accounts\Domain\AccountRepository;
use App\Accounts\Infrastructure\EventSourcedAccountRepository;
use App\EventStore\ConcurrencyConflict;
use App\EventStore\EventMetadata;
use App\EventStore\InMemory\InMemoryEventStore;
use App\EventStore\StreamId;
use App\Ledger\Domain\JournalEntryId;
use App\Ledger\Domain\JournalPostingService;
use App\Ledger\Infrastructure\AccountRepositoryStatusReader;
use App\Ledger\Infrastructure\EventSourcedLedgerRepository;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Tests\Support\FixedClock;
use App\Tests\Support\TransferTestEnvironment;
use App\Transfers\Application\InitiateTransfer;
use App\Transfers\Application\ReverseTransfer;
use App\Transfers\Application\TransferOrchestrator;
use App\Transfers\Domain\Exception\TransferNotReversible;
use App\Transfers\Domain\FailureReason;
use App\Transfers\Domain\TransferId;
use App\Transfers\Domain\TransferStatus;
use App\Transfers\Infrastructure\EventSourcedTransferRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransferOrchestratorTest extends TestCase
{
    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    #[Test]
    public function aFundedTransferCompletesAndMovesTheMoney(): void
    {
        $env = TransferTestEnvironment::inMemory();
        $source = $env->openAccount(10_000);
        $destination = $env->openAccount(0);

        $transfer = $env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $destination, $this->usd(10_000)));

        self::assertSame(TransferStatus::Completed, $transfer->status());
        self::assertTrue($env->accounts->load($source)->totalBalance()->equals($this->usd(0)));
        self::assertTrue($env->accounts->load($destination)->availableBalance()->equals($this->usd(10_000)));

        self::assertNotNull($transfer->journalEntryId());
        $entry = $env->ledger->load(JournalEntryId::fromString($transfer->journalEntryId()));
        self::assertCount(2, $entry->legs());
    }

    #[Test]
    public function insufficientFundsFailsAtHoldWithNoPartialEffects(): void
    {
        $env = TransferTestEnvironment::inMemory();
        $source = $env->openAccount(5_000);
        $destination = $env->openAccount(0);

        $transfer = $env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $destination, $this->usd(10_000)));

        self::assertSame(TransferStatus::Failed, $transfer->status());
        self::assertSame(FailureReason::InsufficientFunds, $transfer->failureReason());
        self::assertNull($transfer->journalEntryId());
        self::assertTrue($env->accounts->load($source)->availableBalance()->equals($this->usd(5_000)));
        self::assertTrue($env->accounts->load($source)->reservedBalance()->equals($this->usd(0)));
        self::assertTrue($env->accounts->load($destination)->availableBalance()->equals($this->usd(0)));
    }

    #[Test]
    public function aFailureAfterTheHoldReleasesTheHold(): void
    {
        $env = TransferTestEnvironment::inMemory();
        $source = $env->openAccount(10_000);
        $destination = $env->openAccount(0);
        $env->closeAccount($destination);

        $transfer = $env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $destination, $this->usd(10_000)));

        self::assertSame(TransferStatus::Failed, $transfer->status());
        self::assertSame(FailureReason::ClosedAccount, $transfer->failureReason());
        // Hold released: available restored, nothing reserved, no money moved.
        self::assertTrue($env->accounts->load($source)->availableBalance()->equals($this->usd(10_000)));
        self::assertTrue($env->accounts->load($source)->reservedBalance()->equals($this->usd(0)));
    }

    #[Test]
    public function aLostConcurrencyRaceAtHoldFailsWithConflict(): void
    {
        $store = new InMemoryEventStore(new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')), TransferTestEnvironment::registry());
        $realAccounts = new EventSourcedAccountRepository($store);

        $sourceId = AccountId::generate();
        $source = Account::open($sourceId, Currency::of('USD'));
        $source->deposit($this->usd(10_000));
        $realAccounts->save($source);
        $destinationId = AccountId::generate();
        $realAccounts->save(Account::open($destinationId, Currency::of('USD')));

        // Simulates losing the race: the source hold append conflicts.
        $conflicting = new class ($realAccounts) implements AccountRepository {
            private bool $thrown = false;

            public function __construct(private readonly EventSourcedAccountRepository $inner) {}

            public function load(AccountId $id): Account
            {
                return $this->inner->load($id);
            }

            public function save(Account $account, ?EventMetadata $metadata = null): void
            {
                if (!$this->thrown) {
                    $this->thrown = true;

                    throw ConcurrencyConflict::forStream(StreamId::of('account', $account->id()->toString()), 1, 2);
                }
                $this->inner->save($account, $metadata);
            }
        };

        $posting = new JournalPostingService(new AccountRepositoryStatusReader($realAccounts));
        $orchestrator = new TransferOrchestrator(
            new EventSourcedTransferRepository($store),
            $conflicting,
            new EventSourcedLedgerRepository($store),
            $posting,
        );

        $transfer = $orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $sourceId, $destinationId, $this->usd(10_000)));

        self::assertSame(TransferStatus::Failed, $transfer->status());
        self::assertSame(FailureReason::Conflict, $transfer->failureReason());
        // Nothing persisted on the source: full balance intact, no hold.
        self::assertTrue($realAccounts->load($sourceId)->availableBalance()->equals($this->usd(10_000)));
        self::assertTrue($realAccounts->load($sourceId)->reservedBalance()->equals($this->usd(0)));
    }

    #[Test]
    public function twoTransfersFromOneSourceWithFundsForOneSettleExactlyOnce(): void
    {
        $env = TransferTestEnvironment::inMemory();
        $source = $env->openAccount(10_000);
        $b = $env->openAccount(0);
        $c = $env->openAccount(0);

        $first = $env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $b, $this->usd(10_000)));
        $second = $env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $c, $this->usd(10_000)));

        $completed = ($first->isCompleted() ? 1 : 0) + ($second->isCompleted() ? 1 : 0);
        self::assertSame(1, $completed, 'Exactly one transfer must complete.');
        self::assertSame(TransferStatus::Failed, $second->status());
        // Source debited exactly once.
        self::assertTrue($env->accounts->load($source)->totalBalance()->equals($this->usd(0)));
    }

    #[Test]
    public function reversingACompletedTransferCreatesACompensatingTransfer(): void
    {
        $env = TransferTestEnvironment::inMemory();
        $source = $env->openAccount(10_000);
        $destination = $env->openAccount(0);

        $original = $env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $destination, $this->usd(10_000)));
        self::assertTrue($original->isCompleted());

        $reversal = $env->orchestrator->reverse(new ReverseTransfer($original->id(), TransferId::generate()));

        self::assertSame(TransferStatus::Completed, $reversal->status());
        self::assertSame($original->id()->toString(), $reversal->reversalOf());
        // Money is back where it started.
        self::assertTrue($env->accounts->load($source)->availableBalance()->equals($this->usd(10_000)));
        self::assertTrue($env->accounts->load($destination)->availableBalance()->equals($this->usd(0)));
        // The original transfer is unchanged.
        self::assertSame(TransferStatus::Completed, $env->transfers->load($original->id())->status());
    }

    #[Test]
    public function reversingANonCompletedTransferIsRejected(): void
    {
        $env = TransferTestEnvironment::inMemory();
        $source = $env->openAccount(5_000);
        $destination = $env->openAccount(0);

        $failed = $env->orchestrator->initiate(new InitiateTransfer(TransferId::generate(), $source, $destination, $this->usd(10_000)));
        self::assertSame(TransferStatus::Failed, $failed->status());

        $this->expectException(TransferNotReversible::class);
        $env->orchestrator->reverse(new ReverseTransfer($failed->id(), TransferId::generate()));
    }
}
