<?php

declare(strict_types=1);

namespace App\Accounts\Domain\Event;

/**
 * Type-safe readers for the loosely-typed payload arrays events are rebuilt from.
 */
trait DecodesPayload
{
    private static function payloadString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function payloadInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
