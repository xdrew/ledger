<?php

declare(strict_types=1);

namespace App\Accounts\Infrastructure;

use App\Accounts\Domain\Account;
use App\Accounts\Domain\AccountId;
use App\Accounts\Domain\AccountRepository;
use App\Accounts\Domain\Exception\AccountNotFound;
use App\EventStore\EventMetadata;
use App\EventStore\EventStore;
use App\EventStore\RecordedEvent;
use App\EventStore\StreamId;
use App\SharedKernel\Event\DomainEvent;

/**
 * Persists accounts as event streams (stream type "account") via the event store.
 */
final class EventSourcedAccountRepository implements AccountRepository
{
    private const STREAM_TYPE = 'account';

    public function __construct(private readonly EventStore $eventStore) {}

    public function load(AccountId $id): Account
    {
        $history = $this->eventStore->load($this->streamId($id));
        if ($history === []) {
            throw AccountNotFound::withId($id);
        }

        $events = array_map(static fn(RecordedEvent $recorded): DomainEvent => $recorded->event, $history);

        return Account::reconstituteFromHistory(...$events);
    }

    public function save(Account $account, ?EventMetadata $metadata = null): void
    {
        $pending = $account->pullUncommittedEvents();
        if ($pending === []) {
            return;
        }

        // The stream's expected version is the aggregate version before the new events.
        $expectedVersion = $account->aggregateVersion() - \count($pending);

        $this->eventStore->append($this->streamId($account->id()), $expectedVersion, $pending, $metadata);
    }

    private function streamId(AccountId $id): StreamId
    {
        return StreamId::of(self::STREAM_TYPE, $id->toString());
    }
}
