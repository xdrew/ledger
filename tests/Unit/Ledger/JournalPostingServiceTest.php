<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ledger;

use App\Ledger\Domain\AccountRef;
use App\Ledger\Domain\AccountStatusReader;
use App\Ledger\Domain\Exception\ClosedAccountPosting;
use App\Ledger\Domain\Exception\CurrencyMismatchPosting;
use App\Ledger\Domain\Exception\FrozenAccountPosting;
use App\Ledger\Domain\Exception\UnknownAccountPosting;
use App\Ledger\Domain\JournalEntryId;
use App\Ledger\Domain\JournalPostingService;
use App\Ledger\Domain\Leg;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JournalPostingServiceTest extends TestCase
{
    private function usd(int $minorUnits): Money
    {
        return Money::of($minorUnits, Currency::of('USD'));
    }

    #[Test]
    public function postsWhenAllAccountsArePostable(): void
    {
        $service = new JournalPostingService($this->readerRejecting(null));

        $entry = $service->post(
            JournalEntryId::generate(),
            Leg::debit(AccountRef::fromString('a'), $this->usd(100)),
            Leg::credit(AccountRef::fromString('b'), $this->usd(100)),
        );

        self::assertCount(1, $entry->pullUncommittedEvents());
    }

    #[Test]
    public function checksEachLegAgainstItsOwnCurrency(): void
    {
        /** @var \ArrayObject<int, string> $checked */
        $checked = new \ArrayObject();
        $reader = new class ($checked) implements AccountStatusReader {
            /**
             * @param \ArrayObject<int, string> $checked
             */
            public function __construct(private readonly \ArrayObject $checked) {}

            public function assertPostable(AccountRef $account, Currency $currency): void
            {
                $this->checked[] = $account->value . ':' . $currency->code;
            }
        };

        (new JournalPostingService($reader))->post(
            JournalEntryId::generate(),
            Leg::debit(AccountRef::fromString('a'), $this->usd(100)),
            Leg::credit(AccountRef::fromString('b'), $this->usd(100)),
        );

        self::assertSame(['a:USD', 'b:USD'], (array) $checked);
    }

    #[Test]
    public function rejectsPostingThatReferencesAClosedAccount(): void
    {
        $service = new JournalPostingService($this->readerRejecting('closed'));

        $this->expectException(ClosedAccountPosting::class);
        $service->post(
            JournalEntryId::generate(),
            Leg::debit(AccountRef::fromString('closed'), $this->usd(100)),
            Leg::credit(AccountRef::fromString('b'), $this->usd(100)),
        );
    }

    #[Test]
    public function rejectsPostingThatReferencesAFrozenAccount(): void
    {
        $reader = new class implements AccountStatusReader {
            public function assertPostable(AccountRef $account, Currency $currency): void
            {
                if ($account->value === 'frozen') {
                    throw FrozenAccountPosting::forAccount($account);
                }
            }
        };

        $this->expectException(FrozenAccountPosting::class);
        (new JournalPostingService($reader))->post(
            JournalEntryId::generate(),
            Leg::debit(AccountRef::fromString('a'), $this->usd(100)),
            Leg::credit(AccountRef::fromString('frozen'), $this->usd(100)),
        );
    }

    #[Test]
    public function rejectsPostingInACurrencyForeignToTheAccount(): void
    {
        $reader = new class implements AccountStatusReader {
            public function assertPostable(AccountRef $account, Currency $currency): void
            {
                if (!$currency->equals(Currency::of('EUR'))) {
                    throw CurrencyMismatchPosting::forAccount($account, Currency::of('EUR'), $currency);
                }
            }
        };

        $this->expectException(CurrencyMismatchPosting::class);
        (new JournalPostingService($reader))->post(
            JournalEntryId::generate(),
            Leg::debit(AccountRef::fromString('a'), $this->usd(100)),
            Leg::credit(AccountRef::fromString('b'), $this->usd(100)),
        );
    }

    #[Test]
    public function rejectsPostingThatReferencesAnUnknownAccount(): void
    {
        $reader = new class implements AccountStatusReader {
            public function assertPostable(AccountRef $account, Currency $currency): void
            {
                if ($account->value === 'ghost') {
                    throw UnknownAccountPosting::forAccount($account);
                }
            }
        };

        $this->expectException(UnknownAccountPosting::class);
        (new JournalPostingService($reader))->post(
            JournalEntryId::generate(),
            Leg::debit(AccountRef::fromString('a'), $this->usd(100)),
            Leg::credit(AccountRef::fromString('ghost'), $this->usd(100)),
        );
    }

    private function readerRejecting(?string $closedRef): AccountStatusReader
    {
        return new class ($closedRef) implements AccountStatusReader {
            public function __construct(private readonly ?string $closedRef) {}

            public function assertPostable(AccountRef $account, Currency $currency): void
            {
                if ($this->closedRef !== null && $account->value === $this->closedRef) {
                    throw ClosedAccountPosting::forAccount($account);
                }
            }
        };
    }
}
