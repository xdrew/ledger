## Why

The ledger runs but is opaque: no way to check liveness/readiness, no metrics, no traces, and logs
are unstructured. This change adds the **observability wrapping** from brief §7 so the system is
operable in production and in the `add-deployment` compose/Helm stack: health probes, Prometheus
metrics (runtime + business), OpenTelemetry tracing across the write→outbox→projection pipeline,
structured JSON logs correlated by id, and the Grafana dashboard + alert-rule deliverables.

## What Changes

- **Health endpoints** (public, outside `/api`, for Kubernetes probes):
  - `GET /healthz` — liveness, no dependencies, always `200` when the worker is up.
  - `GET /readyz` — readiness: checks the database is reachable and the **outbox relay** is live
    (a relay heartbeat is recent); `200` ready / `503` not ready with the failing check named.
- **Metrics (Prometheus)** exposed via the **RoadRunner metrics plugin** on a separate port:
  - Runtime/RR metrics (workers, request latency histograms) come from the plugin.
  - **Business metrics** through a `Metrics` port (RoadRunner adapter in prod, in-memory adapter in
    tests): counters `transfers_total{status}`, `journal_entries_total`, `idempotency_replays_total`;
    gauges `holds_active`, `outbox_pending`, `projection_lag_seconds`. Counters increment at the
    event points; DB-derived gauges are refreshed by a `metrics:collect` command (run on an interval
    by the RoadRunner service scheduler) and by the relay/projection loops.
- **Distributed tracing (OpenTelemetry):** spans at the pipeline seams — command dispatch → outbox
  relay → projection — with event append represented by a **W3C `traceparent` carried in event
  metadata**, so relay/projector spans continue the trace across processes. A no-op tracer unless
  tracing is enabled, so tests/CLI need no collector. The active trace id is added to every log line.
  (The OTLP wire-exporter and a dedicated `http.request` root span are deferred to `add-deployment`:
  the OTLP protobuf exporter conflicts with the RoadRunner packages' protobuf major version.)
- **Structured logging:** Monolog emits **JSON to stderr** (12-factor), level from `LOG_LEVEL`, with
  a processor that adds `correlation_id`, `causation_id`, and `trace_id` to every record.
- **Deliverables:** a **Grafana dashboard JSON** (golden signals + the business metrics) and
  **Prometheus alert rules** (e.g. `projection_lag_seconds > 30` for 5m, `outbox_pending` growing,
  request p99 SLO burn) under `deploy/observability/`.

## Capabilities

### New Capabilities
- `observability`: health probes, Prometheus metrics (runtime + business), OpenTelemetry tracing
  across the write→outbox→projection pipeline, structured JSON logging correlated by id, and the
  Grafana dashboard + Prometheus alert-rule deliverables.

### Modified Capabilities
- `event-store`: event metadata additionally carries an optional **W3C trace context**
  (`traceparent`) so asynchronous consumers (outbox relay, projectors) continue the originating
  trace. Backward compatible — absent on pre-existing events and when tracing is disabled.

## Impact

- **New code:** `App\Observability\` (`Metrics` port + RoadRunner/InMemory/Null adapters; a
  `Tracer` abstraction over the OTel SDK with a no-op default; `metrics:collect` command); health
  `Action`s (`/healthz`, `/readyz`) with a `ReadinessProbe` (DB + relay heartbeat); a Monolog
  JSON formatter config + a correlation/trace processor. Metric increments wired into the transfer
  saga, journal posting, idempotency listener, relay, and projection runner.
- **Config:** `.rr.yaml` / `.rr.dev.yaml` gain a `metrics` plugin (separate port) and a
  `metrics:collect` service; `config/packages/monolog.yaml` switches `main` to the JSON formatter +
  processor; new env (`OTEL_TRACING_ENABLED`, `READINESS_RELAY_MAX_AGE`).
- **Schema:** none — the relay checkpoint's `updated_at` heartbeat column already exists from the
  earlier checkpoint tables; the relay just touches it each loop.
- **Dependencies (new):** `spiral/roadrunner-metrics` (RR metrics RPC); the OpenTelemetry PHP SDK
  (`open-telemetry/sdk`) used manually (no auto-instrumentation extension). The OTLP exporter is
  intentionally not added (protobuf conflict with the RoadRunner packages); it lands in
  `add-deployment`.
- **Depends on** the api, outbox, projections, transfers, ledger, and idempotency capabilities.
  The compose services (`prometheus`, `grafana`, `otel-collector`) and Helm `ServiceMonitor`/probes
  are wired in `add-deployment`; this change provides the endpoints, metrics, spans, and assets they
  consume. The `docs/runbook.md` and `docs/slo.md` write-ups are §8 artifacts, produced later.
