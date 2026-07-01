# observability Specification

## Purpose
TBD - created by archiving change add-observability. Update Purpose after archive.
## Requirements
### Requirement: Liveness endpoint

The system SHALL expose a public `GET /healthz` liveness endpoint that depends on nothing and
returns `200` whenever the worker is serving, requiring no API key.

#### Scenario: Liveness without a key

- **WHEN** `GET /healthz` is called without an API key
- **THEN** the response is `200` with a body indicating the service is alive

### Requirement: Readiness endpoint

The system SHALL expose a public `GET /readyz` readiness endpoint that checks the database is
reachable and the outbox relay is live (its heartbeat is recent), returning `200` when ready and
`503` naming the failed check when not, requiring no API key.

#### Scenario: Ready when dependencies are healthy

- **WHEN** `GET /readyz` is called and the database answers and the relay heartbeat is recent
- **THEN** the response is `200` indicating the service is ready

#### Scenario: Not ready when the relay is stale

- **WHEN** the outbox relay heartbeat is older than the configured threshold
- **THEN** `GET /readyz` responds `503` and names the failing check

### Requirement: Prometheus metrics

The system SHALL expose Prometheus metrics on a dedicated port: RoadRunner runtime metrics (workers,
request latency) from the metrics plugin, plus business metrics — counters `transfers_total{status}`,
`journal_entries_total`, `idempotency_replays_total` and gauges `holds_active`, `outbox_pending`,
`projection_lag_seconds`. Business metrics SHALL be updated through a metrics port so the domain and
application code stay decoupled from the exposition mechanism.

#### Scenario: A completed transfer increments its counter

- **WHEN** a transfer reaches a terminal status
- **THEN** `transfers_total` is incremented for that `status` label

#### Scenario: Outbox backlog is exposed as a gauge

- **WHEN** metrics are collected while events await relay
- **THEN** `outbox_pending` reflects the number of unrelayed events

### Requirement: Distributed tracing

The system SHALL produce OpenTelemetry spans at the pipeline seams — command dispatch, outbox relay,
and projection — linked into a single trace, carrying the trace context across the asynchronous hop
via a W3C `traceparent` stored in event metadata, and SHALL include the active trace id in log
records. Tracing SHALL be a no-op when disabled, so tests and CLI usage need no collector.

#### Scenario: A continuous trace across the async hop

- **WHEN** a command's event is later relayed and projected
- **THEN** the command, relay, and projection spans share one trace id, continued from the event's `traceparent`

#### Scenario: Tracing disabled needs no collector

- **WHEN** tracing is disabled
- **THEN** requests succeed, spans are not recorded, and no tracing error is raised

### Requirement: Structured JSON logging

The system SHALL log structured JSON to stderr with the level taken from the environment, and every
record SHALL carry the `correlation_id`, `causation_id`, and `trace_id` when available.

#### Scenario: A request log carries correlation and trace ids

- **WHEN** a request is handled with a correlation id and an active trace
- **THEN** the emitted JSON log records include `correlation_id` and `trace_id`

### Requirement: Monitoring assets

The system SHALL provide a Grafana dashboard (JSON) covering the golden signals and the business
metrics, and Prometheus alert rules including projection-lag, outbox-backlog, and request-latency
SLO-burn alerts, committed in the repository.

#### Scenario: Dashboard and alert rules are present and valid

- **WHEN** the committed observability assets are loaded
- **THEN** the Grafana dashboard JSON and the Prometheus alert rules parse and contain the expected panels and rules

