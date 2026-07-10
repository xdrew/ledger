<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * The canonical metric names (brief §7). Kept in one place so the increment
 * sites, the collector, and the RoadRunner declaration cannot drift.
 */
final class Metric
{
    public const string TRANSFERS_TOTAL = 'transfers_total';
    public const string JOURNAL_ENTRIES_TOTAL = 'journal_entries_total';
    public const string IDEMPOTENCY_REPLAYS_TOTAL = 'idempotency_replays_total';
    public const string HOLDS_ACTIVE = 'holds_active';
    public const string OUTBOX_PENDING = 'outbox_pending';
    public const string PROJECTION_LAG_SECONDS = 'projection_lag_seconds';
    public const string HTTP_REQUESTS_TOTAL = 'http_requests_total';
    public const string HTTP_REQUEST_DURATION_SECONDS = 'http_request_duration_seconds';
}
