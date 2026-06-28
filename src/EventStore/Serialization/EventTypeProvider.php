<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

/**
 * A bounded context implements this to contribute its event types to the shared
 * registry. Implementations are collected and applied by
 * {@see EventTypeRegistryConfigurator}, so contexts register independently.
 */
interface EventTypeProvider
{
    public function registerInto(EventTypeRegistry $registry): void;
}
