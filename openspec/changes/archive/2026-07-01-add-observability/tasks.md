> Depends on api, outbox, projections, transfers, ledger, idempotency. New deps:
> `spiral/roadrunner-metrics`, `open-telemetry/sdk` (OTLP exporter deferred — protobuf conflict). No
> schema change (the relay heartbeat column already exists). The prometheus/grafana/otel-collector
> compose services and Helm probe/ServiceMonitor wiring are deferred to add-deployment;
> docs/runbook.md + docs/slo.md are §8 artifacts.

## 1. Health & readiness

- [x] 1.1 `App\Ops\Healthz\Action` (`GET /healthz`, public, outside the `/api` firewall) → `200 {"status":"alive"}`.
- [x] 1.2 `App\Ops\ReadinessProbe` checking DB `SELECT 1` and the relay heartbeat age; `App\Ops\Readyz\Action` (`GET /readyz`, public) → `200` ready / `503` naming the failed check.
- [x] 1.3 Heartbeat: the relay checkpoint's `updated_at` column already exists (no migration); the relay `touch()`es it every loop (incl. idle) and `ReadinessProbe` reads its age.

## 2. Metrics

- [x] 2.1 `App\Observability\Metrics` port (counter/gauge/histogram) + `RoadRunnerMetrics` (spiral/roadrunner-metrics RPC), `InMemoryMetrics` (tests), `NullMetrics` (CLI) adapters; DI wires the adapter from env.
- [x] 2.2 Increment counters at the event points: `transfers_total{status}` (saga terminal transition), `journal_entries_total` (journal posted), `idempotency_replays_total` (idempotency listener replay).
- [x] 2.3 `metrics:collect` command setting gauges `holds_active`, `outbox_pending` (head − relay position), `projection_lag_seconds`; refresh opportunistically at the end of the relay/projection loops.
- [x] 2.4 `.rr.yaml` / `.rr.dev.yaml`: enable the RR `metrics` plugin on a separate port (`:2112`) and run `metrics:collect` on an interval via the service scheduler.

## 3. Tracing

- [x] 3.1 `App\Observability\Tracing\Tracer` over the OTel SDK; no-op unless `OTEL_TRACING_ENABLED`. DI + env config. (OTLP exporter deferred to add-deployment — protobuf conflict.)
- [x] 3.2 Spans at the pipeline seams: `command.dispatch` (CommandBus) → `outbox.relay` → `projection.project`.
- [x] 3.3 Carry the W3C `traceparent` in `EventMetadata`; stamp it at append and persist/rehydrate it in `DbalEventStore`; relay/projector spans `continueTrace` from it (see §5 event-store delta).

## 4. Logging

- [x] 4.1 `config/packages/monolog.yaml`: JSON formatter on the `main` handler (stderr), level from `LOG_LEVEL`.
- [x] 4.2 A `CorrelationContext` + Monolog processor adding `correlation_id`, `causation_id`, `trace_id` to every record; populated by an early request listener (HTTP) and from event metadata (workers).

## 5. Deliverables

- [x] 5.1 `deploy/observability/grafana-dashboard.json` — golden signals + the six business metrics.
- [x] 5.2 `deploy/observability/alerts.yaml` — Prometheus rules: `projection_lag_seconds > 30` for 5m, `outbox_pending` steadily increasing, request p99 latency SLO burn.

## 6. Tests

- [x] 6.1 Functional: `/healthz` → `200`; `/readyz` → `200` when healthy and `503` when the relay heartbeat is stale (simulate by ageing the checkpoint); both need no API key.
- [x] 6.2 Unit: business metrics increment via `InMemoryMetrics` at each event point; `metrics:collect` sets the expected gauges.
- [x] 6.3 Unit: the log processor adds `correlation_id`/`causation_id`/`trace_id`; tracing is a no-op with no endpoint configured (no exception, spans still created in-memory).
- [x] 6.4 Asset test: the Grafana dashboard JSON and alert-rule YAML parse and contain the expected panels/rules.

## 7. Verification & gate

- [x] 7.1 Confirm the "done" criteria: probes respond; the metrics port serves the business + runtime metrics; a transfer produces a continuous trace (HTTP → projection) with the trace id in logs; logs are JSON with ids.
- [x] 7.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration + functional suites; `openspec validate add-observability --strict` passes.
