<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

/**
 * Raised when (de)serialization encounters an event type or class that is not
 * registered, so unknown types fail loudly instead of being silently dropped.
 */
final class UnknownEventType extends \RuntimeException
{
    public static function forType(string $type): self
    {
        return new self(\sprintf('No event class is registered for type "%s".', $type));
    }

    public static function forClass(string $class): self
    {
        return new self(\sprintf('Event class "%s" is not registered.', $class));
    }
}
