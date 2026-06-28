<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

use App\EventStore\Aggregate\AggregateRoot;
use App\Ledger\Domain\Event\JournalEntryPosted;
use App\Ledger\Domain\Exception\UnbalancedEntry;
use App\SharedKernel\Event\DomainEvent;

/**
 * An immutable double-entry journal entry: two or more legs that balance per
 * currency. Posted exactly once; never amended (corrections are new entries).
 *
 * Structural invariants (>= 2 legs, balanced, positive amounts via {@see Leg})
 * are enforced here. The contextual "no closed account" rule lives in
 * {@see JournalPostingService}, which needs external account state.
 */
final class JournalEntry extends AggregateRoot
{
    private JournalEntryId $id;

    /** @var list<Leg> */
    private array $legs;

    private function __construct() {}

    public static function post(JournalEntryId $id, Leg ...$legs): self
    {
        $legs = array_values($legs);
        if (\count($legs) < 2) {
            throw UnbalancedEntry::tooFewLegs(\count($legs));
        }
        self::assertBalanced($legs);

        $entry = new self();
        $entry->recordThat(new JournalEntryPosted($id->toString(), $legs));

        return $entry;
    }

    public function id(): JournalEntryId
    {
        return $this->id;
    }

    /**
     * @return list<Leg>
     */
    public function legs(): array
    {
        return $this->legs;
    }

    protected function apply(DomainEvent $event): void
    {
        if ($event instanceof JournalEntryPosted) {
            $this->id = JournalEntryId::fromString($event->entryId);
            $this->legs = $event->legs;
        }
    }

    /**
     * @param list<Leg> $legs
     */
    private static function assertBalanced(array $legs): void
    {
        // Per-currency net of debits (+) minus credits (-); each must be zero.
        $netByCurrency = [];
        foreach ($legs as $leg) {
            $code = $leg->amount->currency->code;
            $signed = $leg->direction === LegDirection::Debit ? $leg->amount->minorUnits : -$leg->amount->minorUnits;
            $netByCurrency[$code] = ($netByCurrency[$code] ?? 0) + $signed;
        }

        foreach ($netByCurrency as $code => $net) {
            if ($net !== 0) {
                throw UnbalancedEntry::forCurrency($code, $net);
            }
        }
    }
}
