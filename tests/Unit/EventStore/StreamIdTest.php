<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventStore;

use App\EventStore\StreamId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamIdTest extends TestCase
{
    #[Test]
    public function rendersAsTypeSlashId(): void
    {
        self::assertSame('account/42', StreamId::of('account', '42')->toString());
    }

    #[Test]
    public function anEmptyTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamId::of('', '42');
    }

    #[Test]
    public function anEmptyIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamId::of('account', '');
    }

    #[Test]
    public function equalityRequiresBothTypeAndId(): void
    {
        $stream = StreamId::of('account', '42');

        self::assertTrue($stream->equals(StreamId::of('account', '42')));
        self::assertFalse($stream->equals(StreamId::of('transfer', '42')));
        self::assertFalse($stream->equals(StreamId::of('account', '43')));
    }
}
