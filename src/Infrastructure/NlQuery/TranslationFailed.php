<?php

declare(strict_types=1);

namespace App\Infrastructure\NlQuery;

/**
 * Raised when a natural-language query cannot be translated to a valid filter —
 * an API failure, a refusal, or output that does not satisfy the filter
 * contract. The endpoint maps this to 502: it never guesses.
 */
final class TranslationFailed extends \RuntimeException
{
    public static function because(string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('Statement query translation failed: %s', $reason), 0, $previous);
    }
}
