<?php

declare(strict_types=1);

namespace App\Projections\Query;

/**
 * Result of a filtered statement query: the matching entries plus SQL-computed
 * aggregates (never model-computed — see the nl-query capability).
 */
final readonly class FilteredStatement
{
    /**
     * @param list<StatementEntry> $entries
     */
    public function __construct(
        public array $entries,
        public int $count,
        public int $sumMinorUnits,
        public ?string $currency,
    ) {}
}
