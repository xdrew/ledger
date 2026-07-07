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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function openStartsActiveWithZeroBalances(): void
    {
        $account = $this->account();

        self::assertSame(AccountStatus::Open, $account->status());
        self::assertTrue($account->isActive());
        self::assertSame('USD', $account->currency()->code);
        self::assertTrue($account->availableBalance()->isZero());
        self::assertTrue($account->reservedBalance()->isZero());
        self::assertTrue($account->totalBalance()->isZero());
    }

    #[Test]
    public function depositIncreasesAvailable(): void
    {
        $account = $this->funded(10_000);

        self::assertTrue($account->availableBalance()->equals($this->usd(10_000)));
        self::assertTrue($account->totalBalance()->equals($this->usd(10_000)));
    }

    #[Test]
    public function holdMovesAvailableToReserved(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));

        self::assertTrue($account->availableBalance()->equals($this->usd(6_000)));
        self::assertTrue($account->reservedBalance()->equals($this->usd(4_000)));
        self::assertTrue($account->totalBalance()->equals($this->usd(10_000)));
    }

    #[Test]
    public function holdBeyondAvailableIsRejectedAndLeavesBalancesUntouched(): void
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

    #[Test]
    public function releaseHoldMovesReservedBackToAvailable(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));
        $account->releaseHold($this->usd(4_000));

        self::assertTrue($account->availableBalance()->equals($this->usd(10_000)));
        self::assertTrue($account->reservedBalance()->isZero());
    }

    #[Test]
    public function releaseBeyondReservedIsRejected(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));

        $this->expectException(InsufficientFunds::class);
        $account->releaseHold($this->usd(5_000));
    }

    #[Test]
    public function debitReducesReservedAndTotal(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));
        $account->debit($this->usd(4_000));

        self::assertTrue($account->reservedBalance()->isZero());
        self::assertTrue($account->totalBalance()->equals($this->usd(6_000)));
    }

    #[Test]
    public function debitBeyondReservedIsRejected(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));

        $this->expectException(InsufficientFunds::class);
        $account->debit($this->usd(5_000));
    }

    #[Test]
    public function creditIncreasesAvailable(): void
    {
        $account = $this->account();
        $account->credit($this->usd(4_000));

        self::assertTrue($account->availableBalance()->equals($this->usd(4_000)));
    }

    #[Test]
    public function freezeMakesAccountInactive(): void
    {
        $account = $this->account();
        $account->freeze();

        self::assertSame(AccountStatus::Frozen, $account->status());
        self::assertFalse($account->isActive());
    }

    #[Test]
    public function closeMakesAccountInactive(): void
    {
        $account = $this->account();
        $account->close();

        self::assertSame(AccountStatus::Closed, $account->status());
        self::assertFalse($account->isActive());
    }

    /**
     * Every money command must carry both guards (active status, valid amount);
     * a funded account with an existing hold makes all five operations viable,
     * so the only acceptable outcome is the guard exception.
     */
    private function fundedWithHold(): Account
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(1_000));

        return $account;
    }

    private function operate(Account $account, string $operation, Money $amount): void
    {
        match ($operation) {
            'deposit' => $account->deposit($amount),
            'hold' => $account->hold($amount),
            'releaseHold' => $account->releaseHold($amount),
            'debit' => $account->debit($amount),
            'credit' => $account->credit($amount),
            default => throw new \LogicException('Unknown operation ' . $operation),
        };
    }

    #[Test]
    #[DataProvider('moneyOperations')]
    public function everyMoneyOperationIsRejectedOnAFrozenAccount(string $operation): void
    {
        $account = $this->fundedWithHold();
        $account->freeze();

        $this->expectException(AccountNotActive::class);
        $this->expectExceptionMessage('frozen');
        $this->operate($account, $operation, $this->usd(100));
    }

    #[Test]
    #[DataProvider('moneyOperations')]
    public function everyMoneyOperationIsRejectedOnAClosedAccount(string $operation): void
    {
        $account = $this->fundedWithHold();
        $account->close();

        $this->expectException(AccountNotActive::class);
        $this->expectExceptionMessage('closed');
        $this->operate($account, $operation, $this->usd(100));
    }

    #[Test]
    #[DataProvider('moneyOperations')]
    public function everyMoneyOperationRejectsAZeroAmount(string $operation): void
    {
        $this->expectException(InvalidAmount::class);
        $this->operate($this->fundedWithHold(), $operation, $this->usd(0));
    }

    #[Test]
    #[DataProvider('moneyOperations')]
    public function everyMoneyOperationRejectsANegativeAmount(string $operation): void
    {
        $this->expectException(InvalidAmount::class);
        $this->operate($this->fundedWithHold(), $operation, $this->usd(-100));
    }

    #[Test]
    #[DataProvider('moneyOperations')]
    public function everyMoneyOperationRejectsAForeignCurrency(string $operation): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->operate($this->fundedWithHold(), $operation, Money::of(100, Currency::of('EUR')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function moneyOperations(): iterable
    {
        foreach (['deposit', 'hold', 'releaseHold', 'debit', 'credit'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[Test]
    public function freezingANonOpenAccountIsRejected(): void
    {
        $account = $this->account();
        $account->freeze();

        $this->expectException(AccountNotActive::class);
        $account->freeze();
    }

    #[Test]
    public function closingAClosedAccountIsRejected(): void
    {
        $account = $this->account();
        $account->close();

        $this->expectException(AccountNotActive::class);
        $account->close();
    }

    #[Test]
    public function reservedStaysWithinBoundsThroughASequence(): void
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

    #[Test]
    public function recordsExpectedEventsAndAdvancesVersion(): void
    {
        $account = $this->funded(10_000);
        $account->hold($this->usd(4_000));

        // open + deposit + hold
        self::assertSame(3, $account->aggregateVersion());
        self::assertCount(3, $account->pullUncommittedEvents());
    }
}
