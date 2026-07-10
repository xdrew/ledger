<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Accounts\Domain\AccountId;
use App\Accounts\Domain\AccountRepository;
use App\Accounts\Domain\AccountStatus;
use App\Accounts\Domain\Exception\AccountNotActive;
use App\Accounts\Domain\Exception\AccountNotFound;
use App\Accounts\Domain\Exception\InsufficientFunds;
use App\EventStore\ConcurrencyConflict;
use App\Ledger\Domain\AccountRef;
use App\Ledger\Domain\Exception\ClosedAccountPosting;
use App\Ledger\Domain\Exception\CurrencyMismatchPosting;
use App\Ledger\Domain\Exception\FrozenAccountPosting;
use App\Ledger\Domain\Exception\UnknownAccountPosting;
use App\Ledger\Domain\JournalEntryId;
use App\Ledger\Domain\JournalPostingService;
use App\Ledger\Domain\LedgerRepository;
use App\Ledger\Domain\Leg;
use App\Observability\Metrics\Metric;
use App\Observability\Metrics\Metrics;
use App\SharedKernel\Money\CurrencyMismatch;
use App\SharedKernel\Money\Money;
use App\Transfers\Domain\Exception\TransferNotReversible;
use App\Transfers\Domain\FailureReason;
use App\Transfers\Domain\Transfer;
use App\Transfers\Domain\TransferRepository;

/**
 * Orchestrates the transfer saga (ADR-005: orchestration, not choreography).
 *
 * Step order is hold -> post journal -> settle so that any failure before the
 * settle step only needs to release the hold to compensate (the funds remain in
 * the source's reserved bucket until settlement).
 */
final readonly class TransferOrchestrator
{
    private const int SETTLE_ATTEMPTS = 3;

    public function __construct(
        private TransferRepository $transfers,
        private AccountRepository $accounts,
        private LedgerRepository $ledger,
        private JournalPostingService $posting,
        private Metrics $metrics,
    ) {}

    public function initiate(InitiateTransfer $command): Transfer
    {
        $transfer = Transfer::initiate(
            $command->transferId,
            $command->sourceAccountId->toString(),
            $command->destinationAccountId->toString(),
            $command->amount,
            $command->reversalOf?->toString(),
        );
        $this->transfers->save($transfer);

        return $this->run($transfer, $command->sourceAccountId, $command->destinationAccountId, $command->amount);
    }

    public function reverse(ReverseTransfer $command): Transfer
    {
        $original = $this->transfers->load($command->originalTransferId);
        if (!$original->isCompleted()) {
            throw TransferNotReversible::notCompleted($command->originalTransferId, $original->status());
        }

        // Swap direction; the original is never modified.
        return $this->initiate(new InitiateTransfer(
            $command->newTransferId,
            AccountId::fromString($original->destinationAccountId()),
            AccountId::fromString($original->sourceAccountId()),
            $original->amount(),
            $command->originalTransferId,
        ));
    }

    private function run(Transfer $transfer, AccountId $sourceId, AccountId $destinationId, Money $amount): Transfer
    {
        // Step 1: hold on the source. Insufficient funds, an unknown or non-open
        // source, a wrong-currency amount or a lost concurrency race fails here
        // with no partial effects.
        try {
            $source = $this->accounts->load($sourceId);
            $source->hold($amount);
            $this->accounts->save($source);
        } catch (InsufficientFunds) {
            return $this->fail($transfer, FailureReason::InsufficientFunds);
        } catch (ConcurrencyConflict) {
            return $this->fail($transfer, FailureReason::Conflict);
        } catch (AccountNotFound) {
            return $this->fail($transfer, FailureReason::UnknownAccount);
        } catch (AccountNotActive $error) {
            return $this->fail($transfer, $error->status === AccountStatus::Frozen ? FailureReason::FrozenAccount : FailureReason::ClosedAccount);
        } catch (CurrencyMismatch) {
            return $this->fail($transfer, FailureReason::CurrencyMismatch);
        }

        $transfer->markHeld();
        $this->transfers->save($transfer);

        // Step 2: post the double-entry journal (also rejects closed accounts).
        try {
            $journalEntry = $this->posting->post(
                JournalEntryId::generate(),
                Leg::debit(AccountRef::fromString($sourceId->toString()), $amount),
                Leg::credit(AccountRef::fromString($destinationId->toString()), $amount),
            );
            $this->ledger->save($journalEntry);
            $this->metrics->incrementCounter(Metric::JOURNAL_ENTRIES_TOTAL);
        } catch (\Throwable $error) {
            $this->releaseHold($sourceId, $amount);

            return $this->fail($transfer, $this->reasonFor($error));
        }

        $transfer->markPosted($journalEntry->id()->toString());
        $this->transfers->save($transfer);

        // Step 3: settle balances. Business preconditions (existence, open
        // status, matching currency, funds) were all enforced by the hold and
        // the posting, so the only expected failure here is a transient
        // concurrency race — retried with a fresh aggregate load. Until the debit applies, the hold is intact and
        // releasing it is the correct compensation. Once the debit has applied
        // the hold no longer exists, so a residual credit failure must NOT
        // "compensate": it propagates, leaving the transfer loudly `posted`
        // (the runbook's stuck-saga play) instead of recording a false `failed`
        // while the source money already moved.
        try {
            $this->settleWithRetry(function () use ($sourceId, $amount): void {
                $source = $this->accounts->load($sourceId);
                $source->debit($amount);
                $this->accounts->save($source);
            });
        } catch (\Throwable $error) {
            $this->releaseHold($sourceId, $amount);

            return $this->fail($transfer, $this->reasonFor($error));
        }

        $this->settleWithRetry(function () use ($destinationId, $amount): void {
            $destination = $this->accounts->load($destinationId);
            $destination->credit($amount);
            $this->accounts->save($destination);
        });

        $transfer->complete();
        $this->transfers->save($transfer);
        $this->metrics->incrementCounter(Metric::TRANSFERS_TOTAL, ['status' => 'completed']);

        return $transfer;
    }

    private function fail(Transfer $transfer, FailureReason $reason): Transfer
    {
        $transfer->fail($reason);
        $this->transfers->save($transfer);
        $this->metrics->incrementCounter(Metric::TRANSFERS_TOTAL, ['status' => 'failed']);

        return $transfer;
    }

    /**
     * Best-effort compensation; residual failures are reconcilable via the ledger
     * trial balance and addressed by the later outbox/retry work.
     */
    private function releaseHold(AccountId $sourceId, Money $amount): void
    {
        try {
            $source = $this->accounts->load($sourceId);
            $source->releaseHold($amount);
            $this->accounts->save($source);
        } catch (\Throwable) {
            // Swallowed deliberately; see method docblock.
        }
    }

    /**
     * Runs one settlement operation, retrying transient concurrency races with a
     * fresh aggregate load. Settlement ops are pure appends (debit against an
     * existing hold, credit), so a conflict only means "someone else appended
     * first" — reload and reapply.
     */
    private function settleWithRetry(callable $operation): void
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $operation();

                return;
            } catch (ConcurrencyConflict $conflict) {
                if ($attempt >= self::SETTLE_ATTEMPTS) {
                    throw $conflict;
                }
            }
        }
    }

    private function reasonFor(\Throwable $error): FailureReason
    {
        return match (true) {
            $error instanceof UnknownAccountPosting => FailureReason::UnknownAccount,
            $error instanceof ClosedAccountPosting => FailureReason::ClosedAccount,
            $error instanceof FrozenAccountPosting => FailureReason::FrozenAccount,
            $error instanceof CurrencyMismatchPosting => FailureReason::CurrencyMismatch,
            $error instanceof ConcurrencyConflict => FailureReason::Conflict,
            default => FailureReason::Other,
        };
    }
}
