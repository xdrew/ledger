<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

/**
 * Applies every registered {@see EventTypeProvider} to the shared
 * {@see EventTypeRegistry}. Wired as the registry's DI configurator so each
 * bounded context contributes its event types without a central list.
 */
final class EventTypeRegistryConfigurator
{
    /**
     * @param iterable<EventTypeProvider> $providers
     */
    public function __construct(private readonly iterable $providers) {}

    public function configure(EventTypeRegistry $registry): void
    {
        foreach ($this->providers as $provider) {
            $provider->registerInto($registry);
        }
    }
}
