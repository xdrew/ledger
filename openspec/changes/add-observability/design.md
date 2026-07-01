## Context

Everything works but is unobservable. This change adds the four observability pillars (health,
metrics, traces, logs) plus the dashboard/alert deliverables, wired into the existing pipeline
(HTTP → `CommandBus` → event append → outbox relay → projection). Constraints from `project.md` /
brief §7: RoadRunner hosts the app and exposes metrics via its plugin; every command already carries
a correlation/causation id (add-message-bus) that must now also reach logs and traces; the domain
stays framework-free (instrumentation lives at the application/infrastructure edges, behind ports).

## Goals / Non-Goals

**Goals:**
- `/healthz` (liveness) and `/readyz` (DB + relay heartbeat) for Kubernetes probes.
- Prometheus metrics: RR runtime metrics from the plugin, plus the six business metrics behind a
  `Metrics` port.
- OpenTelemetry spans spanning the sync request path and the async relay/projection path, with the
  trace id in logs.
- Structured JSON logs with correlation/causation/trace ids; level via env.
- A Grafana dashboard JSON and Prometheus alert rules committed under `deploy/observability/`.

**Non-Goals (later / elsewhere):** the `prometheus`/`grafana`/`otel-collector` compose services and
Helm `ServiceMonitor`/probe wiring (`add-deployment`); `docs/runbook.md` and `docs/slo.md` (§8
artifacts); log shipping/retention; per-tenant metrics.

## Decisions

### D1: Health as public endpoints, readiness via a probe
`GET /healthz` and `GET /readyz` are invokable `Action`s (travel layout) served outside the `/api`
firewall so probes need no key. `/healthz` returns `200 {"status":"alive"}` unconditionally (the
worker answering *is* liveness). `/readyz` runs a `ReadinessProbe` that checks (a) the DB answers
`SELECT 1` and (b) the outbox relay heartbeat is recent; on success `200 {"status":"ready", checks}`,
otherwise `503` naming the failed check. Distinct from the existing `/api/health` (a simple
authless ping kept for API clients).

- *Alternatives rejected:* one combined endpoint (K8s wants separate liveness/readiness semantics);
  putting them under `/api` (probes shouldn't carry an API key).

### D2: Relay heartbeat for readiness
The relay checkpoint row (`projection_checkpoints` name `outbox`) already carries an `updated_at`
column (added with the checkpoint tables in earlier changes), so **no migration is needed**. The
relay `touch()`es it every loop (even when idle); `ReadinessProbe` treats the relay as live when
`now - updated_at < READINESS_RELAY_MAX_AGE` (default 30s).

- *Alternatives rejected:* "outbox not backlogged" as readiness (a *stopped* relay with an empty
  backlog would look ready); a separate heartbeat table (an extra column is enough).

### D3: Metrics behind a `Metrics` port; RoadRunner adapter exposes them
`App\Observability\Metrics` exposes `incrementCounter(name, labels)`, `setGauge(name, value, labels)`,
`observeHistogram(name, value, labels)`. Adapters: `RoadRunnerMetrics` (prod; declares and updates
metrics over the `spiral/roadrunner-metrics` RPC, which RR aggregates across workers and exposes on
the metrics plugin's port), `InMemoryMetrics` (tests; records calls for assertions), `NullMetrics`
(CLI/when RR is absent). Business metrics:
- **Counters at the event points:** `transfers_total{status}` (saga terminal transition),
  `journal_entries_total` (journal posted), `idempotency_replays_total` (replay in the idempotency
  listener).
- **Gauges from DB state:** `holds_active`, `outbox_pending` (`head - relay position`),
  `projection_lag_seconds`. Refreshed by a `metrics:collect` console command run on an interval by
  the RR service scheduler, and opportunistically at the end of the relay/projection loops.

RR runtime metrics (worker count, request latency histograms) come from the RR metrics plugin
directly — no app code. The plugin listens on a **separate port** (`:2112`) so scraping metrics is
isolated from serving traffic.

- *Alternatives rejected:* an in-app `/metrics` endpoint with a PHP Prometheus client (needs shared
  APCu/Redis storage to aggregate counters across RR worker processes — RR's plugin already does
  this); pushing to a statsd/pushgateway (RR pull is simpler here).

### D4: OpenTelemetry tracing with W3C context in event metadata
A `Tracer` abstraction wraps the OTel SDK (`open-telemetry/sdk`); it is a **no-op** unless
`OTEL_TRACING_ENABLED` is set, so tests and CLI need no collector. Spans are created at the pipeline
seams — `command.dispatch` (in `CommandBus`) → `outbox.relay` → `projection.project`. Event append is
represented not by a separate span but by the **`traceparent` stamped into the event metadata** at
append time (from the active command span); the relay and projectors `continueTrace()` from that
`traceparent`, so a transfer's trace is continuous across processes. The active trace id is mirrored
into `CorrelationContext` for logging (D6).

The **OTLP wire-exporter is deferred to `add-deployment`**: `open-telemetry/exporter-otlp` requires
`google/protobuf ^3||^4`, which conflicts with the `protobuf 5` pulled in by the RoadRunner packages.
So the enabled path currently uses the SDK's in-memory exporter (local/dev tracing and tests); the
collector export is wired once that conflict is resolved. A dedicated `http.request` root span is
likewise left for `add-deployment` (it needs split start/end across the RR request lifecycle).

- *Alternatives rejected:* auto-instrumentation (`open-telemetry/opentelemetry-auto-symfony` needs
  the `ext-opentelemetry` C extension — avoid the build dependency; manual spans are explicit and
  enough); trace continuity via correlation id in logs only (loses span linkage in the trace UI).

### D5: `traceparent` in event metadata (modifies event-store)
`EventMetadata` gains an optional `traceparent` (W3C string) beside `correlationId`/`causationId`;
`DbalEventStore` persists and rehydrates it in the metadata JSON. Backward compatible: null on old
events and when tracing is off. This is the one cross-capability edit and is why `event-store` is a
Modified Capability.

### D6: Structured JSON logging with a correlation/trace processor
`config/packages/monolog.yaml` sets the `main` handler's formatter to JSON (`monolog`'s
`JsonFormatter`) and registers a processor that adds `correlation_id`, `causation_id`, and `trace_id`
to every record, sourced from a request-scoped `CorrelationContext` (set by an early request
listener from the incoming/ generated ids and by the workers from event metadata). Level stays
`%env(LOG_LEVEL)%`. Output stays on stderr (12-factor).

### D7: Dashboard and alerts as committed assets
`deploy/observability/grafana-dashboard.json` (golden signals + the six business metrics) and
`deploy/observability/alerts.yaml` (Prometheus rules: `projection_lag_seconds > 30` for 5m,
`outbox_pending` steadily increasing, request p99 latency SLO burn). A small test asserts both files
are present and parse (JSON/YAML) with the expected panels/rules, so they don't rot.

## Risks / Trade-offs

- **OTel SDK surface / overhead** → Mitigation: a no-op tracer by default; sampling configurable;
  spans only at the five pipeline seams, not per-function.
- **RR metrics coupling** → Mitigation: the `Metrics` port keeps the domain/app code adapter-agnostic;
  `InMemoryMetrics` covers tests; `NullMetrics` keeps CLI runnable without RR.
- **Gauge freshness** (collector interval vs. scrape) → Mitigation: short collector interval
  (default 10s) plus opportunistic refresh in the relay/projection loops; document the lag.
- **Readiness flapping** on a slow relay → Mitigation: a generous default `READINESS_RELAY_MAX_AGE`
  (30s) and a heartbeat written even on idle loops.

## Open Questions

- Metrics plugin port and scrape path defaults — start `:2112`; confirm against `add-deployment`.
- Histogram buckets for command latency — start with RR defaults; tune from SLOs (`docs/slo.md`).
- Whether projectors run in the same worker as the relay (affects where async spans start) — settled
  in `add-deployment`'s worker topology; the instrumentation is independent of it.
