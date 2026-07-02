<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventStore;

use App\Accounts\Domain\Event\AccountOpened;
use App\Accounts\Infrastructure\Upcasting\AccountOpenedV1ToV2;
use App\EventStore\Serialization\EventSerializer;
use App\EventStore\Serialization\EventTypeRegistry;
use App\EventStore\Serialization\MissingUpcaster;
use App\EventStore\Serialization\Upcaster;
use App\EventStore\Serialization\UpcasterChain;
use App\Tests\Support\SomethingHappened;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpcasterChainTest extends TestCase
{
    /**
     * A captured v1 payload, as rows written before the account_type field
     * existed actually look. Must never be "refreshed" to the current shape.
     */
    private const CAPTURED_V1_PAYLOAD = ['account_id' => '0195f2c0-0000-7000-8000-000000000001', 'currency' => 'USD'];

    private function accountsRegistry(): EventTypeRegistry
    {
        $registry = new EventTypeRegistry();
        $registry->register('accounts.account_opened', AccountOpened::class, 2);

        return $registry;
    }

    #[Test]
    public function aStoredV1PayloadUpcastsToTheCurrentShape(): void
    {
        $serializer = new EventSerializer($this->accountsRegistry(), new UpcasterChain([new AccountOpenedV1ToV2()]));

        $event = $serializer->deserialize('accounts.account_opened', 1, self::CAPTURED_V1_PAYLOAD);

        self::assertInstanceOf(AccountOpened::class, $event);
        self::assertSame('USD', $event->currency);
        self::assertSame(AccountOpened::DEFAULT_ACCOUNT_TYPE, $event->accountType);
    }

    #[Test]
    public function aCurrentVersionPayloadBypassesTheChain(): void
    {
        // Empty chain: would throw on any upcast attempt, so success proves the bypass.
        $serializer = new EventSerializer($this->accountsRegistry(), new UpcasterChain());

        $event = $serializer->deserialize('accounts.account_opened', 2, [
            'account_id' => 'a-1',
            'currency' => 'USD',
            'account_type' => 'settlement',
        ]);

        self::assertInstanceOf(AccountOpened::class, $event);
        self::assertSame('settlement', $event->accountType);
    }

    #[Test]
    public function aMissingStepFailsLoudly(): void
    {
        $serializer = new EventSerializer($this->accountsRegistry(), new UpcasterChain());

        $this->expectException(MissingUpcaster::class);
        $serializer->deserialize('accounts.account_opened', 1, self::CAPTURED_V1_PAYLOAD);
    }

    #[Test]
    public function upcastersChainAcrossMultipleVersions(): void
    {
        $registry = new EventTypeRegistry();
        $registry->register(SomethingHappened::TYPE, SomethingHappened::class, 3);

        // v1 used "text"; v2 renamed it to "what"; v3 added "amount".
        $chain = new UpcasterChain([
            self::upcaster(SomethingHappened::TYPE, 1, static function (array $payload): array {
                $payload['what'] = $payload['text'] ?? '';
                unset($payload['text']);

                return $payload;
            }),
            self::upcaster(SomethingHappened::TYPE, 2, static fn(array $payload): array => $payload + ['amount' => 0]),
        ]);

        $event = (new EventSerializer($registry, $chain))->deserialize(SomethingHappened::TYPE, 1, ['text' => 'migrated']);

        self::assertInstanceOf(SomethingHappened::class, $event);
        self::assertSame('migrated', $event->what);
        self::assertSame(0, $event->amount);
    }

    #[Test]
    public function aGapInTheMiddleOfTheChainFailsLoudly(): void
    {
        $registry = new EventTypeRegistry();
        $registry->register(SomethingHappened::TYPE, SomethingHappened::class, 3);

        // Only v1->v2 is registered; v2->v3 is the gap.
        $chain = new UpcasterChain([
            self::upcaster(SomethingHappened::TYPE, 1, static fn(array $payload): array => $payload),
        ]);

        $this->expectException(MissingUpcaster::class);
        $this->expectExceptionMessage('from schema version 2');
        (new EventSerializer($registry, $chain))->deserialize(SomethingHappened::TYPE, 1, ['what' => 'x', 'amount' => 1]);
    }

    #[Test]
    public function duplicateStepsAreRejectedAtConstruction(): void
    {
        $this->expectException(MissingUpcaster::class);
        $this->expectExceptionMessage('Duplicate upcaster');

        new UpcasterChain([new AccountOpenedV1ToV2(), new AccountOpenedV1ToV2()]);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $transform
     */
    private static function upcaster(string $type, int $fromVersion, callable $transform): Upcaster
    {
        return new readonly class ($type, $fromVersion, $transform) implements Upcaster {
            /**
             * @param callable(array<string, mixed>): array<string, mixed> $transform
             */
            public function __construct(
                private string $type,
                private int $fromVersion,
                private mixed $transform,
            ) {}

            public function eventType(): string
            {
                return $this->type;
            }

            public function fromVersion(): int
            {
                return $this->fromVersion;
            }

            public function upcast(array $payload): array
            {
                return ($this->transform)($payload);
            }
        };
    }
}
