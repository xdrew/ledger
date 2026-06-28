<?php

declare(strict_types=1);

namespace App\Transfers\Infrastructure;

use App\EventStore\EventMetadata;
use App\EventStore\EventStore;
use App\EventStore\RecordedEvent;
use App\EventStore\StreamId;
use App\SharedKernel\Event\DomainEvent;
use App\Transfers\Domain\Exception\TransferNotFound;
use App\Transfers\Domain\Transfer;
use App\Transfers\Domain\TransferId;
use App\Transfers\Domain\TransferRepository;

/**
 * Persists transfers as event streams (stream type "transfer").
 */
final class EventSourcedTransferRepository implements TransferRepository
{
    private const STREAM_TYPE = 'transfer';

    public function __construct(private readonly EventStore $eventStore) {}

    public function save(Transfer $transfer, ?EventMetadata $metadata = null): void
    {
        $pending = $transfer->pullUncommittedEvents();
        if ($pending === []) {
            return;
        }

        $expectedVersion = $transfer->aggregateVersion() - \count($pending);

        $this->eventStore->append($this->streamId($transfer->id()), $expectedVersion, $pending, $metadata);
    }

    public function load(TransferId $id): Transfer
    {
        $history = $this->eventStore->load($this->streamId($id));
        if ($history === []) {
            throw TransferNotFound::withId($id);
        }

        $events = array_map(static fn(RecordedEvent $recorded): DomainEvent => $recorded->event, $history);

        return Transfer::reconstituteFromHistory(...$events);
    }

    private function streamId(TransferId $id): StreamId
    {
        return StreamId::of(self::STREAM_TYPE, $id->toString());
    }
}
