<?php

declare(strict_types=1);

namespace App\Ledger\Infrastructure;

use App\EventStore\EventMetadata;
use App\EventStore\EventStore;
use App\EventStore\RecordedEvent;
use App\EventStore\StreamId;
use App\Ledger\Domain\Exception\JournalEntryNotFound;
use App\Ledger\Domain\JournalEntry;
use App\Ledger\Domain\JournalEntryId;
use App\Ledger\Domain\LedgerRepository;
use App\SharedKernel\Event\DomainEvent;

/**
 * Persists journal entries as event streams (stream type "journal_entry").
 */
final class EventSourcedLedgerRepository implements LedgerRepository
{
    private const STREAM_TYPE = 'journal_entry';

    public function __construct(private readonly EventStore $eventStore) {}

    public function save(JournalEntry $entry, ?EventMetadata $metadata = null): void
    {
        $pending = $entry->pullUncommittedEvents();
        if ($pending === []) {
            return;
        }

        $expectedVersion = $entry->aggregateVersion() - \count($pending);

        $this->eventStore->append($this->streamId($entry->id()), $expectedVersion, $pending, $metadata);
    }

    public function load(JournalEntryId $id): JournalEntry
    {
        $history = $this->eventStore->load($this->streamId($id));
        if ($history === []) {
            throw JournalEntryNotFound::withId($id);
        }

        $events = array_map(static fn(RecordedEvent $recorded): DomainEvent => $recorded->event, $history);

        return JournalEntry::reconstituteFromHistory(...$events);
    }

    private function streamId(JournalEntryId $id): StreamId
    {
        return StreamId::of(self::STREAM_TYPE, $id->toString());
    }
}
