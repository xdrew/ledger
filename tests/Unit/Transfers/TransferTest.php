<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transfers;

use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Transfers\Domain\Exception\InvalidTransferTransition;
use App\Transfers\Domain\FailureReason;
use App\Transfers\Domain\Transfer;
use App\Transfers\Domain\TransferId;
use App\Transfers\Domain\TransferStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransferTest extends TestCase
{
    private function transfer(): Transfer
    {
        return Transfer::initiate(TransferId::generate(), 'source', 'destination', Money::of(100, Currency::of('USD')));
    }

    #[Test]
    public function initiatingPlacesItInTheInitiatedState(): void
    {
        $transfer = $this->transfer();

        self::assertSame(TransferStatus::Initiated, $transfer->status());
        self::assertCount(1, $transfer->pullUncommittedEvents());
    }

    #[Test]
    public function theHappyPathTransitionsThroughEachState(): void
    {
        $transfer = $this->transfer();
        $transfer->markHeld();
        self::assertSame(TransferStatus::Held, $transfer->status());
        $transfer->markPosted('journal-1');
        self::assertSame(TransferStatus::Posted, $transfer->status());
        $transfer->complete();
        self::assertSame(TransferStatus::Completed, $transfer->status());
    }

    #[Test]
    public function completingBeforePostingIsRejected(): void
    {
        $transfer = $this->transfer();
        $transfer->markHeld();

        $this->expectException(InvalidTransferTransition::class);
        $transfer->complete();
    }

    #[Test]
    public function markingHeldFromTheWrongStateIsRejected(): void
    {
        $transfer = $this->transfer();
        $transfer->markHeld();

        $this->expectException(InvalidTransferTransition::class);
        $transfer->markHeld();
    }

    #[Test]
    public function postingBeforeTheHoldIsRejected(): void
    {
        $transfer = $this->transfer();

        $this->expectException(InvalidTransferTransition::class);
        $transfer->markPosted('journal-1');
    }

    #[Test]
    public function aCompletedTransferCannotBeFailed(): void
    {
        $transfer = $this->transfer();
        $transfer->markHeld();
        $transfer->markPosted('journal-1');
        $transfer->complete();

        $this->expectException(InvalidTransferTransition::class);
        $transfer->fail(FailureReason::Other);
    }

    #[Test]
    public function failingRecordsTheReason(): void
    {
        $transfer = $this->transfer();
        $transfer->fail(FailureReason::InsufficientFunds);

        self::assertSame(TransferStatus::Failed, $transfer->status());
        self::assertSame(FailureReason::InsufficientFunds, $transfer->failureReason());
    }
}
