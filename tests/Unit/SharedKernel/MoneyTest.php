<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel;

use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\CurrencyMismatch;
use App\SharedKernel\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    #[Test]
    public function addingSameCurrency(): void
    {
        self::assertTrue($this->usd(150)->equals($this->usd(100)->add($this->usd(50))));
    }

    #[Test]
    public function subtractingSameCurrency(): void
    {
        self::assertTrue($this->usd(40)->equals($this->usd(100)->subtract($this->usd(60))));
    }

    #[Test]
    public function zeroAndPredicates(): void
    {
        self::assertTrue(Money::zero(Currency::of('USD'))->isZero());
        self::assertTrue($this->usd(1)->isPositive());
        self::assertTrue($this->usd(-1)->isNegative());
        self::assertFalse($this->usd(0)->isPositive());
        self::assertFalse($this->usd(0)->isNegative());
    }

    #[Test]
    public function comparisons(): void
    {
        self::assertTrue($this->usd(100)->isGreaterThan($this->usd(50)));
        self::assertTrue($this->usd(50)->isLessThan($this->usd(100)));
        self::assertTrue($this->usd(50)->isGreaterThanOrEqual($this->usd(50)));
        self::assertFalse($this->usd(50)->isGreaterThan($this->usd(50)));
        self::assertFalse($this->usd(50)->isLessThan($this->usd(50)));
    }

    #[Test]
    public function everyComparisonRejectsAForeignCurrency(): void
    {
        $eur = Money::of(50, Currency::of('EUR'));

        $rejected = [];
        foreach (['isGreaterThan', 'isGreaterThanOrEqual', 'isLessThan'] as $comparison) {
            try {
                $this->usd(100)->{$comparison}($eur);
            } catch (CurrencyMismatch) {
                $rejected[] = $comparison;
            }
        }

        self::assertSame(['isGreaterThan', 'isGreaterThanOrEqual', 'isLessThan'], $rejected);
    }

    #[Test]
    public function equalityConsidersCurrency(): void
    {
        self::assertFalse($this->usd(100)->equals(Money::of(100, Currency::of('EUR'))));
    }

    #[Test]
    public function addingDifferentCurrenciesIsRejected(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->usd(100)->add(Money::of(50, Currency::of('EUR')));
    }

    #[Test]
    public function comparingDifferentCurrenciesIsRejected(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->usd(100)->isGreaterThan(Money::of(50, Currency::of('EUR')));
    }

    #[Test]
    public function subtractingDifferentCurrenciesIsRejected(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->usd(100)->subtract(Money::of(50, Currency::of('EUR')));
    }
}
