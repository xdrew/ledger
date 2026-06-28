<?php

declare(strict_types=1);

namespace App\Tests\Unit\Accounts;

use App\Accounts\Domain\Account;
use App\Accounts\Domain\AccountId;
use App\Accounts\Domain\AccountStatus;
use App\Accounts\Domain\Exception\AccountNotActive;
use App\Accounts\Domain\Exception\InsufficientFunds;
use App\Accounts\Domain\Exception\InvalidAmount;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\CurrencyMismatch;
use App\SharedKernel\Money\Money;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    private function account(): Account
    {
        return Account::open(AccountId::generate(), Currency::of('USD'));
    }

    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    private function funded(int $minorUnits): Account
    {
        $account = $this->account();
        $account->deposit($this->usd($minorUnits));

        return $account;
    }

    public function testOpenStartsActiveWithZeroBalances(): void
    {
        $account = $this->account();

        self::assertSame(AccountStatus::Open, $account->status());
        self::assertTrue($account->isActive());
        self::assertSame('USD', $account->currency()->code);
        self::assertTrue($account->availableBalance()->isZero());
        self::assertTrue($account->reservedBalance()->isZero());
        self::assertTrue($account->totalBalance()->isZero());
    }

    public function testDepositIncreasesAvailable(): void
    {
        $account = $this->funded(10_000);

        self::assertTrue($account->availableBalance()->equals($this->usd(10_000)));
        self::assertTrue($account->totalBalance()->equals($this->usd(10_000)));
    }

    public function testHoldMovesAvailableToReserved(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));

        self::assertTrue($account->availableBalance()->equals($this->usd(6_000)));
        self::assertTrue($account->reservedBalance()->equals($this->usd(4_000)));
        self::assertTrue($account->totalBalance()->equals($this->usd(10_000)));
    }

    public function testHoldBeyondAvailableIsRejectedAndLeavesBalancesUntouched(): void
    {
        $account = $this->funded(10_000);
        $account->pullUncommittedEvents();

        try {
            $account->hold($this->usd(15_000));
            self::fail('Expected InsufficientFunds.');
        } catch (InsufficientFunds) {
            // expected
        }

        self::assertTrue($account->availableBalance()->equals($this->usd(10_000)));
        self::assertFalse($account->availableBalance()->isNegative());
        self::assertSame([], $account->pullUncommittedEvents());
    }

    public function testReleaseHoldMovesReservedBackToAvailable(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));
        $account->releaseHold($this->usd(4_000));

        self::assertTrue($account->availableBalance()->equals($this->usd(10_000)));
        self::assertTrue($account->reservedBalance()->isZero());
    }

    public function testReleaseBeyondReservedIsRejected(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));

        $this->expectException(InsufficientFunds::class);
        $account->releaseHold($this->usd(5_000));
    }

    public function testDebitReducesReservedAndTotal(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));
        $account->debit($this->usd(4_000));

        self::assertTrue($account->reservedBalance()->isZero());
        self::assertTrue($account->totalBalance()->equals($this->usd(6_000)));
    }

    public function testDebitBeyondReservedIsRejected(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));

        $this->expectException(InsufficientFunds::class);
        $account->debit($this->usd(5_000));
    }

    public function testCreditIncreasesAvailable(): void
    {
        $account = $this->account();
        $account->credit($this->usd(4_000));

        self::assertTrue($account->availableBalance()->equals($this->usd(4_000)));
    }

    public function testFreezeMakesAccountInactive(): void
    {
        $account = $this->account();
        $account->freeze();

        self::assertSame(AccountStatus::Frozen, $account->status());
        self::assertFalse($account->isActive());
    }

    public function testCloseMakesAccountInactive(): void
    {
        $account = $this->account();
        $account->close();

        self::assertSame(AccountStatus::Closed, $account->status());
        self::assertFalse($account->isActive());
    }

    public function testOperationsOnFrozenAccountAreRejected(): void
    {
        $account = $this->funded(10_000);
        $account->freeze();

        $this->expectException(AccountNotActive::class);
        $account->deposit($this->usd(100));
    }

    public function testOperationsOnClosedAccountAreRejected(): void
    {
        $account = $this->funded(10_000);
        $account->close();

        $this->expectException(AccountNotActive::class);
        $account->hold($this->usd(100));
    }

    public function testZeroAmountIsRejected(): void
    {
        $this->expectException(InvalidAmount::class);
        $this->account()->deposit($this->usd(0));
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(InvalidAmount::class);
        $this->account()->deposit($this->usd(-100));
    }

    public function testDifferentCurrencyIsRejected(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->account()->deposit(Money::of(100, Currency::of('EUR')));
    }

    public function testReservedStaysWithinBoundsThroughASequence(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(3_000));
        $account->hold($this->usd(2_000));
        $account->releaseHold($this->usd(1_000));
        $account->debit($this->usd(2_000));

        $reserved = $account->reservedBalance();
        $total = $account->totalBalance();

        self::assertFalse($reserved->isNegative());
        self::assertTrue($reserved->isLessThan($total) || $reserved->equals($total));
        self::assertFalse($account->availableBalance()->isNegative());
    }

    public function testRecordsExpectedEventsAndAdvancesVersion(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));

        // open + deposit + hold
        self::assertSame(3, $account->aggregateVersion());
        self::assertCount(3, $account->pullUncommittedEvents());
    }
}
