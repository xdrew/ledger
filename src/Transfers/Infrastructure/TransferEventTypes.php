<?php

declare(strict_types=1);

namespace App\Transfers\Infrastructure;

use App\EventStore\Serialization\EventTypeProvider;
use App\EventStore\Serialization\EventTypeRegistry;
use App\SharedKernel\Event\DomainEvent;
use App\Transfers\Domain\Event\TransferCompleted;
use App\Transfers\Domain\Event\TransferFailed;
use App\Transfers\Domain\Event\TransferHeld;
use App\Transfers\Domain\Event\TransferInitiated;
use App\Transfers\Domain\Event\TransferPosted;

/**
 * Contributes the transfer event types to the shared registry (tagged provider).
 */
final class TransferEventTypes implements EventTypeProvider
{
    /**
     * @var array<string, class-string<DomainEvent>>
     */
    private const array TYPES = [
        'transfers.transfer_initiated' => TransferInitiated::class,
        'transfers.transfer_held' => TransferHeld::class,
        'transfers.transfer_posted' => TransferPosted::class,
        'transfers.transfer_completed' => TransferCompleted::class,
        'transfers.transfer_failed' => TransferFailed::class,
    ];

    public function registerInto(EventTypeRegistry $registry): void
    {
        foreach (self::TYPES as $type => $class) {
            $registry->register($type, $class);
        }
    }
}
