<?php

declare(strict_types=1);

namespace App\Ledger\Domain\Exception;

use App\Ledger\Domain\JournalEntryId;

/**
 * Raised when loading a journal entry that has no event stream.
 */
final class JournalEntryNotFound extends \RuntimeException
{
    public static function withId(JournalEntryId $id): self
    {
        return new self(\sprintf('Journal entry "%s" not found.', $id->toString()));
    }
}
