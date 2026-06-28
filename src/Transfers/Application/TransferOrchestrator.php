<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Accounts\Domain\AccountId;
use App\Accounts\Domain\AccountRepository;
use App\Accounts\Domain\Exception\InsufficientFunds;
use App\EventStore\ConcurrencyConflict;
use App\Ledger\Domain\AccountRef;
use App\Ledger\Domain\Exception\ClosedAccountPosting;
use App\Ledger\Domain\JournalEntryId;
use App\Ledger\Domain\JournalPostingService;
use App\Ledger\Domain\LedgerRepository;
use App\Ledger\Domain\Leg;
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
final class TransferOrchestrator
{
    public function __construct(
        private readonly TransferRepository $transfers,
        private readonly AccountRepository $accounts,
        private readonly LedgerRepository $ledger,
        private readonly JournalPostingService $posting,
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
        // Step 1: hold on the source. Insufficient funds or a lost concurrency
        // race fails here with no partial effects.
        try {
            $source = $this->accounts->load($sourceId);
            $source->hold($amount);
            $this->accounts->save($source);
        } catch (InsufficientFunds) {
            return $this->fail($transfer, FailureReason::InsufficientFunds);
        } catch (ConcurrencyConflict) {
            return $this->fail($transfer, FailureReason::Conflict);
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
        } catch (\Throwable $error) {
            $this->releaseHold($sourceId, $amount);

            return $this->fail($transfer, $this->reasonFor($error));
        }

        $transfer->markPosted($journalEntry->id()->toString());
        $this->transfers->save($transfer);

        // Step 3: settle balances.
        try {
            $source = $this->accounts->load($sourceId);
            $source->debit($amount);
            $this->accounts->save($source);

            $destination = $this->accounts->load($destinationId);
            $destination->credit($amount);
            $this->accounts->save($destination);
        } catch (\Throwable $error) {
            $this->releaseHold($sourceId, $amount);

            return $this->fail($transfer, $this->reasonFor($error));
        }

        $transfer->complete();
        $this->transfers->save($transfer);

        return $transfer;
    }

    private function fail(Transfer $transfer, FailureReason $reason): Transfer
    {
        $transfer->fail($reason);
        $this->transfers->save($transfer);

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

    private function reasonFor(\Throwable $error): FailureReason
    {
        return match (true) {
            $error instanceof ClosedAccountPosting => FailureReason::ClosedAccount,
            $error instanceof ConcurrencyConflict => FailureReason::Conflict,
            default => FailureReason::Other,
        };
    }
}
