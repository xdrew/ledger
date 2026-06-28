<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

use App\EventStore\EventMetadata;
use App\Ledger\Domain\Exception\JournalEntryNotFound;

interface LedgerRepository
{
    public function save(JournalEntry $entry, ?EventMetadata $metadata = null): void;

    /**
     * @throws JournalEntryNotFound when no stream exists for the id
     */
    public function load(JournalEntryId $id): JournalEntry;
}
