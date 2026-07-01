<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * The canonical metric names (brief §7). Kept in one place so the increment
 * sites, the collector, and the RoadRunner declaration cannot drift.
 */
final class Metric
{
    public const TRANSFERS_TOTAL = 'transfers_total';
    public const JOURNAL_ENTRIES_TOTAL = 'journal_entries_total';
    public const IDEMPOTENCY_REPLAYS_TOTAL = 'idempotency_replays_total';
    public const HOLDS_ACTIVE = 'holds_active';
    public const OUTBOX_PENDING = 'outbox_pending';
    public const PROJECTION_LAG_SECONDS = 'projection_lag_seconds';
}
