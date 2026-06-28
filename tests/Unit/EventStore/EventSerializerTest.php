<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventStore;

use App\EventStore\Serialization\EventSerializer;
use App\EventStore\Serialization\EventTypeRegistry;
use App\EventStore\Serialization\UnknownEventType;
use App\Tests\Support\SomethingHappened;
use PHPUnit\Framework\TestCase;

final class EventSerializerTest extends TestCase
{
    private function serializer(): EventSerializer
    {
        $registry = new EventTypeRegistry();
        $registry->register(SomethingHappened::TYPE, SomethingHappened::class, 2);

        return new EventSerializer($registry);
    }

    public function testSerializeCapturesTypeSchemaAndPayload(): void
    {
        $serialized = $this->serializer()->serialize(new SomethingHappened('paid', 500));

        self::assertSame(SomethingHappened::TYPE, $serialized->type);
        self::assertSame(2, $serialized->schemaVersion);
        self::assertSame(['what' => 'paid', 'amount' => 500], $serialized->payload);
    }

    public function testRoundTripPreservesTheEvent(): void
    {
        $serializer = $this->serializer();
        $original = new SomethingHappened('paid', 500);

        $serialized = $serializer->serialize($original);
        $restored = $serializer->deserialize($serialized->type, $serialized->schemaVersion, $serialized->payload);

        self::assertInstanceOf(SomethingHappened::class, $restored);
        self::assertSame('paid', $restored->what);
        self::assertSame(500, $restored->amount);
    }

    public function testDeserializeUnknownTypeFailsLoudly(): void
    {
        $this->expectException(UnknownEventType::class);
        $this->serializer()->deserialize('test.never_registered', 1, []);
    }
}
