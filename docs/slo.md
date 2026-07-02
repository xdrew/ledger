# ledger-core — service level objectives

SLOs for the ledger, tied one-to-one to the shipped Prometheus alert rules
(`deploy/observability/alerts.yaml`) and the Grafana **Ledger Core** dashboard. Targets are
deliberate engineering budgets for a demo-scale system: honest numbers we can measure today, not
marketing nines. Each would be re-negotiated with real traffic history.

## SLOs

| # | SLO | Target | Measurement (PromQL) | Window | Burn alert |
| --- | --- | --- | --- | --- | --- |
| 1 | **API request latency** — p99 of all HTTP requests (transfers are synchronous sagas, so this bounds transfer latency) | p99 ≤ **500 ms** | `histogram_quantile(0.99, sum(rate(rr_http_request_duration_seconds_bucket[5m])) by (le))` | 5m rolling; reviewed over 30d | `RequestLatencyP99SloBurn` |
| 2 | **Projection freshness** — staleness of read models (balances/statements) behind the event log | lag ≤ **30 s** (typical ~1 s) | `projection_lag_seconds` | instantaneous; alert requires 5m sustained | `ProjectionLagHigh` |
| 3 | **Event publication** — outbox drains; no unbounded backlog | backlog < **100** events and not growing | `outbox_pending`, `delta(outbox_pending[10m])` | 10m trend | `OutboxBacklogGrowing` |
| 4 | **Availability** — api and worker are up and scrapeable; readiness (db + relay heartbeat) holds | **99.5%** monthly (error budget ≈ 3.6 h/month) | `avg_over_time(up[30d])` per target; `/readyz` success rate | 30d | `NotReady` |

## Notes on the targets

- **Why p99 = 500 ms (SLO 1):** a transfer executes hold → journal post → settle synchronously —
  five-plus aggregate appends in one request. 500 ms leaves headroom for retries on optimistic
  conflicts while staying interactive; the dashboard's latency panel and status-code panel give
  the supporting golden signals.
- **Why 30 s lag (SLO 2):** reads are eventually consistent by design (ADR-003) and responses
  expose the projection `version`; 30 s is the point where staleness stops being cosmetic and
  starts breaking client expectations (e.g. a deposit not visible on refresh). Planned rebuilds
  (runbook play) intentionally violate this — silence the alert for the window.
- **Why backlog 100 (SLO 3):** at demo throughput the relay drains each poll cycle; a standing
  queue of 100+ that *grows* for 10 minutes means publication has stalled (dead relay) or hit its
  ceiling — both actionable (runbook: outbox backlog).
- **Why 99.5% (SLO 4):** single-database, no multi-AZ story — promising more without the
  infrastructure would be fiction. The degradation model (runbook) matters more than the number:
  loss of worker/projections degrades freshness (SLOs 2–3) while writes stay correct; only losing
  the api or PostgreSQL touches SLO 4.

## Error budget policy

Burning an SLO's budget (alert firing) pauses feature work on the affected path in favour of the
runbook play and, if chronic, the scaling mitigation named in `docs/design.md` §100x (partitioned
projections/relay for SLOs 2–3; api scale-out or hot-account splitting for SLO 1). SLO review
happens whenever the alert thresholds change — the two must move together, by construction of this
document.
