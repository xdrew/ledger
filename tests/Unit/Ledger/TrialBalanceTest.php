<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ledger;

use App\EventStore\InMemory\InMemoryEventStore;
use App\EventStore\Serialization\EventTypeRegistry;
use App\Ledger\Domain\AccountRef;
use App\Ledger\Domain\Event\JournalEntryPosted;
use App\Ledger\Domain\JournalEntry;
use App\Ledger\Domain\JournalEntryId;
use App\Ledger\Domain\Leg;
use App\Ledger\Domain\LegDirection;
use App\Ledger\Infrastructure\EventSourcedLedgerRepository;
use App\Ledger\Infrastructure\LedgerEventTypes;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrialBalanceTest extends TestCase
{
    /** @var list<string> */
    private const ACCOUNTS = ['acc-1', 'acc-2', 'acc-3', 'acc-4', 'acc-5'];

    #[Test]
    public function manyRandomBalancedEntriesReconcileToGlobalZero(): void
    {
        // Property test: every generated entry is balanced by construction, so
        // the global net is zero for any random sequence (no seed needed).
        $registry = new EventTypeRegistry();
        (new LedgerEventTypes())->registerInto($registry);
        $store = new InMemoryEventStore(new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')), $registry);
        $repository = new EventSourcedLedgerRepository($store);

        for ($i = 0; $i < 100; ++$i) {
            $repository->save($this->randomBalancedEntry());
        }

        $netByAccount = [];
        $globalNet = 0;
        foreach ($store->readFrom(0, 1_000_000) as $recorded) {
            $event = $recorded->event;
            self::assertInstanceOf(JournalEntryPosted::class, $event);
            foreach ($event->legs as $leg) {
                $signed = $leg->direction === LegDirection::Debit ? $leg->amount->minorUnits : -$leg->amount->minorUnits;
                $netByAccount[$leg->account->value] = ($netByAccount[$leg->account->value] ?? 0) + $signed;
                $globalNet += $signed;
            }
        }

        self::assertSame(0, $globalNet, 'Global debits minus credits must net to zero.');
        self::assertSame(0, array_sum($netByAccount), 'Per-account nets must sum to zero.');
    }

    private function randomBalancedEntry(): JournalEntry
    {
        $total = random_int(1, 1_000) * 100;
        $legs = [Leg::debit($this->randomAccount(), Money::of($total, Currency::of('USD')))];

        if (random_int(0, 1) === 0) {
            $legs[] = Leg::credit($this->randomAccount(), Money::of($total, Currency::of('USD')));
        } else {
            $first = random_int(1, $total - 1);
            $legs[] = Leg::credit($this->randomAccount(), Money::of($first, Currency::of('USD')));
            $legs[] = Leg::credit($this->randomAccount(), Money::of($total - $first, Currency::of('USD')));
        }

        return JournalEntry::post(JournalEntryId::generate(), ...$legs);
    }

    private function randomAccount(): AccountRef
    {
        return AccountRef::fromString(self::ACCOUNTS[array_rand(self::ACCOUNTS)]);
    }
}
