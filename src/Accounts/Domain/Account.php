<?php

declare(strict_types=1);

namespace App\Accounts\Domain;

use App\Accounts\Domain\Event\AccountClosed;
use App\Accounts\Domain\Event\AccountFrozen;
use App\Accounts\Domain\Event\AccountOpened;
use App\Accounts\Domain\Event\FundsCredited;
use App\Accounts\Domain\Event\FundsDebited;
use App\Accounts\Domain\Event\FundsDeposited;
use App\Accounts\Domain\Event\FundsHeld;
use App\Accounts\Domain\Event\HoldReleased;
use App\Accounts\Domain\Exception\AccountNotActive;
use App\Accounts\Domain\Exception\InsufficientFunds;
use App\Accounts\Domain\Exception\InvalidAmount;
use App\EventStore\Aggregate\AggregateRoot;
use App\SharedKernel\Event\DomainEvent;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\CurrencyMismatch;
use App\SharedKernel\Money\Money;

/**
 * Account aggregate. Holds money as available + reserved balances and enforces
 * the money-safety invariants: operations only while open, strictly positive
 * amounts, matching currency, non-negative available, and reserved in [0, total].
 *
 * Command methods validate then record an event; state changes happen only in
 * {@see apply()} so replay is side-effect-free.
 */
final class Account extends AggregateRoot
{
    private AccountId $id;

    private Currency $currency;

    private AccountStatus $status;

    private Money $available;

    private Money $reserved;

    private function __construct() {}

    public static function open(AccountId $id, Currency $currency, string $accountType = AccountOpened::DEFAULT_ACCOUNT_TYPE): self
    {
        $account = new self();
        $account->recordThat(new AccountOpened($id->toString(), $currency->code, $accountType));

        return $account;
    }

    public function deposit(Money $amount): void
    {
        $this->assertActive();
        $this->assertValidAmount($amount);
        $this->recordThat(new FundsDeposited($this->id->toString(), $amount->minorUnits, $amount->currency->code));
    }

    public function hold(Money $amount): void
    {
        $this->assertActive();
        $this->assertValidAmount($amount);
        if ($this->available->isLessThan($amount)) {
            throw InsufficientFunds::available($amount, $this->available);
        }
        $this->recordThat(new FundsHeld($this->id->toString(), $amount->minorUnits, $amount->currency->code));
    }

    public function releaseHold(Money $amount): void
    {
        $this->assertActive();
        $this->assertValidAmount($amount);
        if ($this->reserved->isLessThan($amount)) {
            throw InsufficientFunds::reserved($amount, $this->reserved);
        }
        $this->recordThat(new HoldReleased($this->id->toString(), $amount->minorUnits, $amount->currency->code));
    }

    public function debit(Money $amount): void
    {
        $this->assertActive();
        $this->assertValidAmount($amount);
        if ($this->reserved->isLessThan($amount)) {
            throw InsufficientFunds::reserved($amount, $this->reserved);
        }
        $this->recordThat(new FundsDebited($this->id->toString(), $amount->minorUnits, $amount->currency->code));
    }

    public function credit(Money $amount): void
    {
        $this->assertActive();
        $this->assertValidAmount($amount);
        $this->recordThat(new FundsCredited($this->id->toString(), $amount->minorUnits, $amount->currency->code));
    }

    public function freeze(): void
    {
        $this->assertActive();
        $this->recordThat(new AccountFrozen($this->id->toString()));
    }

    public function close(): void
    {
        $this->assertActive();
        $this->recordThat(new AccountClosed($this->id->toString()));
    }

    public function id(): AccountId
    {
        return $this->id;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function availableBalance(): Money
    {
        return $this->available;
    }

    public function reservedBalance(): Money
    {
        return $this->reserved;
    }

    public function totalBalance(): Money
    {
        return $this->available->add($this->reserved);
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Open;
    }

    protected function apply(DomainEvent $event): void
    {
        switch (true) {
            case $event instanceof AccountOpened:
                $this->id = AccountId::fromString($event->accountId);
                $this->currency = Currency::of($event->currency);
                $this->status = AccountStatus::Open;
                $this->available = Money::zero($this->currency);
                $this->reserved = Money::zero($this->currency);

                break;
            case $event instanceof FundsDeposited:
                $this->available = $this->available->add($this->toMoney($event->amountMinorUnits));

                break;
            case $event instanceof FundsHeld:
                $this->available = $this->available->subtract($this->toMoney($event->amountMinorUnits));
                $this->reserved = $this->reserved->add($this->toMoney($event->amountMinorUnits));

                break;
            case $event instanceof HoldReleased:
                $this->reserved = $this->reserved->subtract($this->toMoney($event->amountMinorUnits));
                $this->available = $this->available->add($this->toMoney($event->amountMinorUnits));

                break;
            case $event instanceof FundsDebited:
                $this->reserved = $this->reserved->subtract($this->toMoney($event->amountMinorUnits));

                break;
            case $event instanceof FundsCredited:
                $this->available = $this->available->add($this->toMoney($event->amountMinorUnits));

                break;
            case $event instanceof AccountFrozen:
                $this->status = AccountStatus::Frozen;

                break;
            case $event instanceof AccountClosed:
                $this->status = AccountStatus::Closed;

                break;
        }
    }

    private function toMoney(int $minorUnits): Money
    {
        return Money::of($minorUnits, $this->currency);
    }

    private function assertActive(): void
    {
        if ($this->status !== AccountStatus::Open) {
            throw AccountNotActive::forStatus($this->status);
        }
    }

    private function assertValidAmount(Money $amount): void
    {
        if (!$amount->currency->equals($this->currency)) {
            throw CurrencyMismatch::between($this->currency, $amount->currency);
        }
        if (!$amount->isPositive()) {
            throw InvalidAmount::mustBePositive($amount);
        }
    }
}
