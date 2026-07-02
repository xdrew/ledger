<?php

declare(strict_types=1);

namespace App\Tests\Unit\Projections;

use App\Projections\Query\StatementFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StatementFilterTest extends TestCase
{
    #[Test]
    public function buildsFromAValidPayload(): void
    {
        $filter = StatementFilter::fromArray([
            'entry_types' => ['deposit', 'credit'],
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'min_amount' => 1_000,
            'max_amount' => 50_000,
            'aggregation' => 'sum',
        ]);

        self::assertSame(['deposit', 'credit'], $filter->entryTypes);
        self::assertSame('2026-06-01', $filter->dateFrom?->format('Y-m-d'));
        self::assertSame('2026-06-30', $filter->dateTo?->format('Y-m-d'));
        self::assertSame(1_000, $filter->minAmount);
        self::assertSame(50_000, $filter->maxAmount);
        self::assertSame('sum', $filter->aggregation);
    }

    #[Test]
    public function nullsAndEmptyListMeanUnconstrained(): void
    {
        $filter = StatementFilter::fromArray([
            'entry_types' => [],
            'date_from' => null,
            'date_to' => null,
            'min_amount' => null,
            'max_amount' => null,
            'aggregation' => 'list',
        ]);

        self::assertNull($filter->entryTypes);
        self::assertNull($filter->dateFrom);
        self::assertNull($filter->minAmount);
    }

    #[Test]
    public function rejectsUnknownEntryTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatementFilter::fromArray(['entry_types' => ['withdrawal'], 'aggregation' => 'list']);
    }

    #[Test]
    public function rejectsAnInvertedDateRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatementFilter::fromArray(['date_from' => '2026-07-01', 'date_to' => '2026-06-01', 'aggregation' => 'list']);
    }

    #[Test]
    public function rejectsAnInvertedAmountRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatementFilter::fromArray(['min_amount' => 100, 'max_amount' => 50, 'aggregation' => 'list']);
    }

    #[Test]
    public function rejectsUnknownAggregations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatementFilter::fromArray(['aggregation' => 'average']);
    }

    #[Test]
    public function rejectsNegativeAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatementFilter::fromArray(['min_amount' => -5, 'aggregation' => 'list']);
    }

    #[Test]
    public function echoesTheAppliedFilter(): void
    {
        $filter = StatementFilter::fromArray([
            'entry_types' => ['debit'],
            'date_from' => '2026-06-01',
            'aggregation' => 'count',
        ]);

        self::assertSame([
            'entry_types' => ['debit'],
            'date_from' => '2026-06-01',
            'date_to' => null,
            'min_amount' => null,
            'max_amount' => null,
            'aggregation' => 'count',
        ], $filter->toArray());
    }
}
