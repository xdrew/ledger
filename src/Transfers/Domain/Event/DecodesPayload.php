<?php

declare(strict_types=1);

namespace App\Transfers\Domain\Event;

/**
 * Type-safe readers for the loosely-typed payload arrays events are rebuilt from.
 */
trait DecodesPayload
{
    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function nullableStr(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
