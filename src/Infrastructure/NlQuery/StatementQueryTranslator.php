<?php

declare(strict_types=1);

namespace App\Infrastructure\NlQuery;

use App\Projections\Query\StatementFilter;

/**
 * Port: translate a natural-language statement question into a structured
 * filter. Implementations produce the filter only — they never see statement
 * data and never execute anything.
 */
interface StatementQueryTranslator
{
    /**
     * @param \DateTimeImmutable $today anchors relative ranges ("last month", "June")
     *
     * @throws TranslationFailed when no valid filter can be produced
     */
    public function translate(string $query, \DateTimeImmutable $today): StatementFilter;
}
