<?php

declare(strict_types=1);

namespace App\Tests\Integration\Outbox;

use App\Accounts\Domain\Account;
use App\Accounts\Domain\AccountId;
use App\Accounts\Infrastructure\AccountEventTypes;
use App\Accounts\Infrastructure\EventSourcedAccountRepository;
use App\EventStore\Dbal\DbalEventStore;
use App\EventStore\RecordedEvent;
use App\EventStore\Serialization\EventSerializer;
use App\EventStore\Serialization\EventTypeRegistry;
use App\Outbox\Dbal\ConsumedEvents;
use App\Outbox\Dbal\RelayCheckpoint;
use App\Outbox\EventPublisher;
use App\Outbox\InMemoryEventPublisher;
use App\Outbox\OutboxRelay;
use App\SharedKernel\Clock\SystemClock;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class OutboxRelayTest extends KernelTestCase
{
    private static bool $migrated = false;

    private Connection $connection;

    private DbalEventStore $store;

    private EventSourcedAccountRepository $accounts;

    private RelayCheckpoint $checkpoint;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        if (!self::$migrated) {
            $application = new Application($kernel);
            $tester = new CommandTester($application->find('doctrine:migrations:migrate'));
            $tester->execute(['--no-interaction' => true], ['interactive' => false]);
            $tester->assertCommandIsSuccessful();
            self::$migrated = true;
        }

        $this->connection->executeStatement('TRUNCATE events RESTART IDENTITY');
        $this->connection->executeStatement('TRUNCATE projection_checkpoints, consumed_events');

        $registry = new EventTypeRegistry();
        (new AccountEventTypes())->registerInto($registry);
        $this->store = new DbalEventStore($this->connection, new EventSerializer($registry), new SystemClock());
        $this->accounts = new EventSourcedAccountRepository($this->store);
        $this->checkpoint = new RelayCheckpoint($this->connection);
    }

    private function appendEvents(int $count): void
    {
        $account = Account::open(AccountId::generate(), Currency::of('USD'));
        for ($i = 1; $i < $count; ++$i) {
            $account->deposit(Money::of(100, Currency::of('USD')));
        }
        $this->accounts->save($account);
    }

    #[Test]
    public function relayPublishesEveryEventInOrder(): void
    {
        $this->appendEvents(5);
        $publisher = new InMemoryEventPublisher();

        $published = (new OutboxRelay($this->store, $publisher, $this->checkpoint))->relay();

        self::assertSame(5, $published);
        self::assertSame([1, 2, 3, 4, 5], $publisher->publishedPositions());
        self::assertSame(5, $this->checkpoint->position());
    }

    #[Test]
    public function aPublishFailureDoesNotAdvanceTheCheckpoint(): void
    {
        $this->appendEvents(3);

        $failing = new class implements EventPublisher {
            private int $calls = 0;

            public function publish(RecordedEvent $event): void
            {
                ++$this->calls;
                if ($this->calls === 2) {
                    throw new \RuntimeException('transport down');
                }
            }
        };

        try {
            (new OutboxRelay($this->store, $failing, $this->checkpoint))->relay();
            self::fail('Expected the publish failure to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(1, $this->checkpoint->position(), 'Checkpoint must not advance past the failed event.');

        // Retry resumes from the checkpoint — no event lost.
        $publisher = new InMemoryEventPublisher();
        (new OutboxRelay($this->store, $publisher, $this->checkpoint))->relay();
        self::assertSame([2, 3], $publisher->publishedPositions());
        self::assertSame(3, $this->checkpoint->position());
    }

    #[Test]
    public function killingTheRelayMidBatchThenRestartingLosesNoEvents(): void
    {
        $this->appendEvents(5);
        $publisher = new InMemoryEventPublisher();
        $relay = new OutboxRelay($this->store, $publisher, $this->checkpoint);

        $relay->relay(2); // interrupted after two
        self::assertSame([1, 2], $publisher->publishedPositions());
        self::assertSame(2, $this->checkpoint->position());

        $relay->relay(); // restart
        self::assertSame([1, 2, 3, 4, 5], $publisher->publishedPositions());
        self::assertSame(5, $this->checkpoint->position());
    }

    #[Test]
    public function anIdempotentConsumerAppliesAReDeliveredEventOnce(): void
    {
        $this->appendEvents(1);
        $event = $this->store->readFrom(0, 10)[0];
        $consumed = new ConsumedEvents($this->connection);

        $applied = 0;
        foreach ([$event, $event] as $delivery) {
            if ($consumed->markConsumed('test-consumer', $delivery->eventId->toString())) {
                ++$applied;
            }
        }

        self::assertSame(1, $applied, 'A re-delivered event must be applied only once.');
    }
}
