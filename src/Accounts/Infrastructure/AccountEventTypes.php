<?php

declare(strict_types=1);

namespace App\Accounts\Infrastructure;

use App\Accounts\Domain\Event\AccountClosed;
use App\Accounts\Domain\Event\AccountFrozen;
use App\Accounts\Domain\Event\AccountOpened;
use App\Accounts\Domain\Event\FundsCredited;
use App\Accounts\Domain\Event\FundsDebited;
use App\Accounts\Domain\Event\FundsDeposited;
use App\Accounts\Domain\Event\FundsHeld;
use App\Accounts\Domain\Event\HoldReleased;
use App\EventStore\Serialization\EventTypeRegistry;
use App\SharedKernel\Event\DomainEvent;

/**
 * Single source of truth for the account event type names. Used both to
 * configure the shared registry in DI and to set up registries in tests, so the
 * two never drift.
 */
final class AccountEventTypes
{
    /**
     * @var array<string, class-string<DomainEvent>>
     */
    private const TYPES = [
        'accounts.account_opened' => AccountOpened::class,
        'accounts.funds_deposited' => FundsDeposited::class,
        'accounts.funds_held' => FundsHeld::class,
        'accounts.hold_released' => HoldReleased::class,
        'accounts.funds_debited' => FundsDebited::class,
        'accounts.funds_credited' => FundsCredited::class,
        'accounts.account_frozen' => AccountFrozen::class,
        'accounts.account_closed' => AccountClosed::class,
    ];

    public static function registerInto(EventTypeRegistry $registry): void
    {
        foreach (self::TYPES as $type => $class) {
            $registry->register($type, $class);
        }
    }
}
