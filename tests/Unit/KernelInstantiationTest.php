<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit smoke test: no container boot, no database. Proves autoloading and
 * the kernel wiring are sane so the `unit` suite is meaningful from day one.
 */
final class KernelInstantiationTest extends TestCase
{
    #[Test]
    public function kernelReportsItsEnvironment(): void
    {
        $kernel = new Kernel('test', false);

        self::assertSame('test', $kernel->getEnvironment());
        self::assertFalse($kernel->isDebug());
    }
}
