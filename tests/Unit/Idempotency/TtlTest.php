<?php

declare(strict_types=1);

namespace App\Tests\Unit\Idempotency;

use App\Idempotency\Ttl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TtlTest extends TestCase
{
    #[Test]
    public function expiresExactlyTtlSecondsLater(): void
    {
        $from = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertEquals(
            new \DateTimeImmutable('2026-01-01T00:01:30+00:00'),
            Ttl::ofSeconds(90)->expiresFrom($from),
        );
    }

    #[Test]
    public function zeroSecondsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ttl::ofSeconds(0);
    }

    #[Test]
    public function negativeSecondsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ttl::ofSeconds(-1);
    }
}
