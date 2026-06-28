<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel;

use App\SharedKernel\Money\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function testValidCodeIsAccepted(): void
    {
        self::assertSame('USD', Currency::of('USD')->code);
    }

    public function testEquality(): void
    {
        self::assertTrue(Currency::of('USD')->equals(Currency::of('USD')));
        self::assertFalse(Currency::of('USD')->equals(Currency::of('EUR')));
    }

    #[DataProvider('provideInvalidCodeIsRejectedCases')]
    public function testInvalidCodeIsRejected(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Currency::of($code);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideInvalidCodeIsRejectedCases(): iterable
    {
        return [['usd'], ['US'], ['USDD'], [''], ['12A']];
    }
}
