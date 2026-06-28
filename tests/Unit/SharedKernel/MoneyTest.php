<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel;

use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\CurrencyMismatch;
use App\SharedKernel\Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    public function testAddingSameCurrency(): void
    {
        self::assertTrue($this->usd(150)->equals($this->usd(100)->add($this->usd(50))));
    }

    public function testSubtractingSameCurrency(): void
    {
        self::assertTrue($this->usd(40)->equals($this->usd(100)->subtract($this->usd(60))));
    }

    public function testZeroAndPredicates(): void
    {
        self::assertTrue(Money::zero(Currency::of('USD'))->isZero());
        self::assertTrue($this->usd(1)->isPositive());
        self::assertTrue($this->usd(-1)->isNegative());
        self::assertFalse($this->usd(0)->isPositive());
    }

    public function testComparisons(): void
    {
        self::assertTrue($this->usd(100)->isGreaterThan($this->usd(50)));
        self::assertTrue($this->usd(50)->isLessThan($this->usd(100)));
        self::assertTrue($this->usd(50)->isGreaterThanOrEqual($this->usd(50)));
    }

    public function testEqualityConsidersCurrency(): void
    {
        self::assertFalse($this->usd(100)->equals(Money::of(100, Currency::of('EUR'))));
    }

    public function testAddingDifferentCurrenciesIsRejected(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->usd(100)->add(Money::of(50, Currency::of('EUR')));
    }

    public function testComparingDifferentCurrenciesIsRejected(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->usd(100)->isGreaterThan(Money::of(50, Currency::of('EUR')));
    }
}
