<?php

declare(strict_types=1);

namespace App\Projections\Query;

/**
 * Result of a filtered statement query: the matching entries plus SQL-computed
 * aggregates (never model-computed — see the nl-query capability).
 */
final class FilteredStatement
{
    /**
     * @param list<StatementEntry> $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $count,
        public readonly int $sumMinorUnits,
        public readonly ?string $currency,
    ) {}
}
