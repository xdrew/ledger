<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ledger;

use App\Ledger\Domain\AccountRef;
use App\Ledger\Domain\Event\JournalEntryPosted;
use App\Ledger\Domain\Exception\InvalidLegAmount;
use App\Ledger\Domain\Exception\UnbalancedEntry;
use App\Ledger\Domain\JournalEntry;
use App\Ledger\Domain\JournalEntryId;
use App\Ledger\Domain\Leg;
use App\Ledger\Domain\LegDirection;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JournalEntryTest extends TestCase
{
    private function ref(string $id): AccountRef
    {
        return AccountRef::fromString($id);
    }

    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    #[Test]
    public function postsABalancedTwoLegEntry(): void
    {
        $entry = JournalEntry::post(
            JournalEntryId::generate(),
            Leg::debit($this->ref('a'), $this->usd(100)),
            Leg::credit($this->ref('b'), $this->usd(100)),
        );

        $events = $entry->pullUncommittedEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(JournalEntryPosted::class, $events[0]);
        self::assertCount(2, $entry->legs());
    }

    #[Test]
    public function postsABalancedMultiLegEntry(): void
    {
        $entry = JournalEntry::post(
            JournalEntryId::generate(),
            Leg::debit($this->ref('a'), $this->usd(100)),
            Leg::credit($this->ref('b'), $this->usd(70)),
            Leg::credit($this->ref('c'), $this->usd(30)),
        );

        self::assertCount(3, $entry->legs());
    }

    #[Test]
    public function rejectsFewerThanTwoLegs(): void
    {
        $this->expectException(UnbalancedEntry::class);
        JournalEntry::post(JournalEntryId::generate(), Leg::debit($this->ref('a'), $this->usd(100)));
    }

    #[Test]
    public function rejectsAnUnbalancedEntry(): void
    {
        $this->expectException(UnbalancedEntry::class);
        JournalEntry::post(
            JournalEntryId::generate(),
            Leg::debit($this->ref('a'), $this->usd(100)),
            Leg::credit($this->ref('b'), $this->usd(90)),
        );
    }

    #[Test]
    public function rejectsANonPositiveLegAmount(): void
    {
        $this->expectException(InvalidLegAmount::class);
        Leg::debit($this->ref('a'), $this->usd(0));
    }

    #[Test]
    public function rehydratesFromHistoryWithoutDifference(): void
    {
        $id = JournalEntryId::generate();
        $entry = JournalEntry::post(
            $id,
            Leg::debit($this->ref('a'), $this->usd(100)),
            Leg::credit($this->ref('b'), $this->usd(100)),
        );

        $rebuilt = JournalEntry::reconstituteFromHistory(...$entry->pullUncommittedEvents());

        self::assertTrue($rebuilt->id()->equals($id));
        self::assertCount(2, $rebuilt->legs());
        self::assertSame(LegDirection::Debit, $rebuilt->legs()[0]->direction);
        self::assertSame('a', $rebuilt->legs()[0]->account->value);
        self::assertTrue($rebuilt->legs()[0]->amount->equals($this->usd(100)));
        self::assertSame([], $rebuilt->pullUncommittedEvents());
    }
}
