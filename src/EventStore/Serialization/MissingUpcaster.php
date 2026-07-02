<?php

declare(strict_types=1);

namespace App\EventStore\Serialization;

/**
 * Raised when a stored event is older than its type's current schema version and
 * no upcaster covers a required step. Failing loudly is deliberate: building an
 * event from a stale payload would fabricate state (ADR-006).
 */
final class MissingUpcaster extends \RuntimeException
{
    public static function forStep(string $type, int $fromVersion): self
    {
        return new self(\sprintf(
            'No upcaster registered for event type "%s" from schema version %d.',
            $type,
            $fromVersion,
        ));
    }

    public static function duplicateStep(string $type, int $fromVersion): self
    {
        return new self(\sprintf(
            'Duplicate upcaster registered for event type "%s" from schema version %d.',
            $type,
            $fromVersion,
        ));
    }
}
