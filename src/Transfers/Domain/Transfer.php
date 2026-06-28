<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

use App\EventStore\Aggregate\AggregateRoot;
use App\SharedKernel\Event\DomainEvent;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Transfers\Domain\Event\TransferCompleted;
use App\Transfers\Domain\Event\TransferFailed;
use App\Transfers\Domain\Event\TransferHeld;
use App\Transfers\Domain\Event\TransferInitiated;
use App\Transfers\Domain\Event\TransferPosted;
use App\Transfers\Domain\Exception\InvalidTransferTransition;

/**
 * The transfer saga as an event-sourced aggregate. Its state machine encodes the
 * lifecycle: Initiated -> Held -> Posted -> Completed, with -> Failed reachable
 * from any non-terminal state. The orchestrator records each transition as the
 * corresponding step succeeds or fails; transitions are validated here.
 */
final class Transfer extends AggregateRoot
{
    private TransferId $id;

    private string $sourceAccountId;

    private string $destinationAccountId;

    private int $amountMinorUnits;

    private string $currency;

    private ?string $reversalOf = null;

    private TransferStatus $status;

    private ?string $journalEntryId = null;

    private ?FailureReason $failureReason = null;

    private function __construct() {}

    public static function initiate(
        TransferId $id,
        string $sourceAccountId,
        string $destinationAccountId,
        Money $amount,
        ?string $reversalOf = null,
    ): self {
        $transfer = new self();
        $transfer->recordThat(new TransferInitiated(
            $id->toString(),
            $sourceAccountId,
            $destinationAccountId,
            $amount->minorUnits,
            $amount->currency->code,
            $reversalOf,
        ));

        return $transfer;
    }

    public function markHeld(): void
    {
        $this->assertStatus(TransferStatus::Initiated);
        $this->recordThat(new TransferHeld($this->id->toString()));
    }

    public function markPosted(string $journalEntryId): void
    {
        $this->assertStatus(TransferStatus::Held);
        $this->recordThat(new TransferPosted($this->id->toString(), $journalEntryId));
    }

    public function complete(): void
    {
        $this->assertStatus(TransferStatus::Posted);
        $this->recordThat(new TransferCompleted($this->id->toString()));
    }

    public function fail(FailureReason $reason): void
    {
        if ($this->status === TransferStatus::Completed || $this->status === TransferStatus::Failed) {
            throw InvalidTransferTransition::cannotFail($this->status);
        }
        $this->recordThat(new TransferFailed($this->id->toString(), $reason->value));
    }

    public function id(): TransferId
    {
        return $this->id;
    }

    public function status(): TransferStatus
    {
        return $this->status;
    }

    public function sourceAccountId(): string
    {
        return $this->sourceAccountId;
    }

    public function destinationAccountId(): string
    {
        return $this->destinationAccountId;
    }

    public function amount(): Money
    {
        return Money::of($this->amountMinorUnits, Currency::of($this->currency));
    }

    public function reversalOf(): ?string
    {
        return $this->reversalOf;
    }

    public function journalEntryId(): ?string
    {
        return $this->journalEntryId;
    }

    public function failureReason(): ?FailureReason
    {
        return $this->failureReason;
    }

    public function isCompleted(): bool
    {
        return $this->status === TransferStatus::Completed;
    }

    protected function apply(DomainEvent $event): void
    {
        switch (true) {
            case $event instanceof TransferInitiated:
                $this->id = TransferId::fromString($event->transferId);
                $this->sourceAccountId = $event->sourceAccountId;
                $this->destinationAccountId = $event->destinationAccountId;
                $this->amountMinorUnits = $event->amountMinorUnits;
                $this->currency = $event->currency;
                $this->reversalOf = $event->reversalOf;
                $this->status = TransferStatus::Initiated;

                break;
            case $event instanceof TransferHeld:
                $this->status = TransferStatus::Held;

                break;
            case $event instanceof TransferPosted:
                $this->status = TransferStatus::Posted;
                $this->journalEntryId = $event->journalEntryId;

                break;
            case $event instanceof TransferCompleted:
                $this->status = TransferStatus::Completed;

                break;
            case $event instanceof TransferFailed:
                $this->status = TransferStatus::Failed;
                $this->failureReason = FailureReason::from($event->reason);

                break;
        }
    }

    private function assertStatus(TransferStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw InvalidTransferTransition::expected($expected, $this->status);
        }
    }
}
