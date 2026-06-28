<?php

declare(strict_types=1);

namespace App\Ledger\Infrastructure;

use App\EventStore\Serialization\EventTypeProvider;
use App\EventStore\Serialization\EventTypeRegistry;
use App\Ledger\Domain\Event\JournalEntryPosted;
use App\SharedKernel\Event\DomainEvent;

/**
 * Single source of truth for the ledger event type names; contributed to the
 * shared registry as a tagged {@see EventTypeProvider} and reused in tests.
 */
final class LedgerEventTypes implements EventTypeProvider
{
    /**
     * @var array<string, class-string<DomainEvent>>
     */
    private const TYPES = [
        'ledger.journal_entry_posted' => JournalEntryPosted::class,
    ];

    public function registerInto(EventTypeRegistry $registry): void
    {
        foreach (self::TYPES as $type => $class) {
            $registry->register($type, $class);
        }
    }
}
