<?php

declare(strict_types=1);

namespace App\Ledger\Domain\Event;

use App\Ledger\Domain\AccountRef;
use App\Ledger\Domain\Leg;
use App\Ledger\Domain\LegDirection;
use App\SharedKernel\Event\DomainEvent;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;

final readonly class JournalEntryPosted implements DomainEvent
{
    /**
     * @param list<Leg> $legs
     */
    public function __construct(
        public string $entryId,
        public array $legs,
    ) {}

    public function toPayload(): array
    {
        return [
            'entry_id' => $this->entryId,
            'legs' => array_map(
                static fn(Leg $leg): array => [
                    'account_ref' => $leg->account->value,
                    'direction' => $leg->direction->value,
                    'amount' => $leg->amount->minorUnits,
                    'currency' => $leg->amount->currency->code,
                ],
                $this->legs,
            ),
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $rawLegs = $payload['legs'] ?? [];
        $legs = [];
        if (\is_array($rawLegs)) {
            foreach ($rawLegs as $rawLeg) {
                if (!\is_array($rawLeg)) {
                    continue;
                }
                $legs[] = Leg::of(
                    AccountRef::fromString(self::str($rawLeg['account_ref'] ?? '')),
                    LegDirection::from(self::str($rawLeg['direction'] ?? '')),
                    Money::of(self::int($rawLeg['amount'] ?? 0), Currency::of(self::str($rawLeg['currency'] ?? ''))),
                );
            }
        }

        return new self(self::str($payload['entry_id'] ?? ''), $legs);
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
