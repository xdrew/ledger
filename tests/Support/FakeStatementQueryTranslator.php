<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\NlQuery\StatementQueryTranslator;
use App\Infrastructure\NlQuery\TranslationFailed;
use App\Projections\Query\StatementFilter;

/**
 * Deterministic keyword-based translator used by the test suite: CI exercises
 * the endpoint, filter, flag, and failure behavior with no network calls.
 */
final class FakeStatementQueryTranslator implements StatementQueryTranslator
{
    public function translate(string $query, \DateTimeImmutable $today): StatementFilter
    {
        $q = strtolower($query);

        if (str_contains($q, 'fail')) {
            throw TranslationFailed::because('the fake translator was asked to fail');
        }

        $payload = [
            'entry_types' => null,
            'date_from' => null,
            'date_to' => null,
            'min_amount' => null,
            'max_amount' => null,
            'aggregation' => 'list',
        ];

        foreach (StatementFilter::ENTRY_TYPES as $type) {
            if (str_contains($q, $type)) {
                $payload['entry_types'] = [$type];

                break;
            }
        }

        if (str_contains($q, 'how much')) {
            $payload['aggregation'] = 'sum';
        } elseif (str_contains($q, 'how many')) {
            $payload['aggregation'] = 'count';
        }

        if (str_contains($q, 'june')) {
            $year = $today->format('Y');
            $payload['date_from'] = $year . '-06-01';
            $payload['date_to'] = $year . '-06-30';
        }

        return StatementFilter::fromArray($payload);
    }
}
