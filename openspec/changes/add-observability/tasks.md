> Depends on api, outbox, projections, transfers, ledger, idempotency. New deps:
> `spiral/roadrunner-metrics`, `open-telemetry/sdk` + OTLP exporter. One schema change (relay
> heartbeat column, generated migration). The prometheus/grafana/otel-collector compose services and
> Helm probe/ServiceMonitor wiring are deferred to add-deployment; docs/runbook.md + docs/slo.md are
> §8 artifacts.

## 1. Health & readiness

- [ ] 1.1 `App\Api\Health\Live\Action` (`GET /healthz`, public) → `200 {"status":"alive"}`.
- [ ] 1.2 `ReadinessProbe` checking DB `SELECT 1` and the outbox relay heartbeat age; `App\Api\Health\Ready\Action` (`GET /readyz`, public) → `200` ready / `503` naming the failed check.
- [ ] 1.3 Add `updated_at` to the relay checkpoint in `LedgerSchemaProvider`; generate the migration via `doctrine:migrations:diff`; write the heartbeat every relay loop (incl. idle).

## 2. Metrics

- [ ] 2.1 `App\Observability\Metrics` port (counter/gauge/histogram) + `RoadRunnerMetrics` (spiral/roadrunner-metrics RPC), `InMemoryMetrics` (tests), `NullMetrics` (CLI) adapters; DI wires the adapter from env.
- [ ] 2.2 Increment counters at the event points: `transfers_total{status}` (saga terminal transition), `journal_entries_total` (journal posted), `idempotency_replays_total` (idempotency listener replay).
- [ ] 2.3 `metrics:collect` command setting gauges `holds_active`, `outbox_pending` (head − relay position), `projection_lag_seconds`; refresh opportunistically at the end of the relay/projection loops.
- [ ] 2.4 `.rr.yaml` / `.rr.dev.yaml`: enable the RR `metrics` plugin on a separate port (`:2112`) and run `metrics:collect` on an interval via the service scheduler.

## 3. Tracing

- [ ] 3.1 `App\Observability\Tracer` over the OTel SDK + OTLP exporter; no-op when `OTEL_EXPORTER_OTLP_ENDPOINT` is unset. DI + env config.
- [ ] 3.2 Spans at the pipeline seams: `http.request` (root) → `command.dispatch` (CommandBus) → `event.append` (event store) → `outbox.relay` → `projection.project`.
- [ ] 3.3 Carry the W3C `traceparent` in `EventMetadata`; persist/rehydrate it in `DbalEventStore`; relay/projector spans continue the originating trace (see §5 event-store delta).

## 4. Logging

- [ ] 4.1 `config/packages/monolog.yaml`: JSON formatter on the `main` handler (stderr), level from `LOG_LEVEL`.
- [ ] 4.2 A `CorrelationContext` + Monolog processor adding `correlation_id`, `causation_id`, `trace_id` to every record; populated by an early request listener (HTTP) and from event metadata (workers).

## 5. Deliverables

- [ ] 5.1 `deploy/observability/grafana-dashboard.json` — golden signals + the six business metrics.
- [ ] 5.2 `deploy/observability/alerts.yaml` — Prometheus rules: `projection_lag_seconds > 30` for 5m, `outbox_pending` steadily increasing, request p99 latency SLO burn.

## 6. Tests

- [ ] 6.1 Functional: `/healthz` → `200`; `/readyz` → `200` when healthy and `503` when the relay heartbeat is stale (simulate by ageing the checkpoint); both need no API key.
- [ ] 6.2 Unit: business metrics increment via `InMemoryMetrics` at each event point; `metrics:collect` sets the expected gauges.
- [ ] 6.3 Unit: the log processor adds `correlation_id`/`causation_id`/`trace_id`; tracing is a no-op with no endpoint configured (no exception, spans still created in-memory).
- [ ] 6.4 Asset test: the Grafana dashboard JSON and alert-rule YAML parse and contain the expected panels/rules.

## 7. Verification & gate

- [ ] 7.1 Confirm the "done" criteria: probes respond; the metrics port serves the business + runtime metrics; a transfer produces a continuous trace (HTTP → projection) with the trace id in logs; logs are JSON with ids.
- [ ] 7.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration + functional suites; `openspec validate add-observability --strict` passes.
