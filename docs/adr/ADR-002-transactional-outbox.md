# ADR-002 — Transactional outbox for event publication

Status: Accepted (2026-06-28) · Implemented by `add-outbox` (`src/Outbox/`).

## Context

Domain events must reach external consumers (projections today; other services tomorrow) without
the classic dual-write hazard: committing to the database and *then* publishing to a broker are two
systems — if the process dies between them, the world has diverged. The brief requires at-least-once
delivery with a demonstrable "kill the relay mid-batch, lose nothing, observably double-deliver
nothing" property.

## Decision

**The event log is the outbox.** Because the write side is event-sourced (ADR-001), the events are
already committed atomically with the state change — there is nothing extra to write. A relay
(`OutboxRelay`) tails the `events` table from a checkpoint (`RelayCheckpoint`, a row in
`projection_checkpoints`), publishing each event in `global_position` order and advancing the
checkpoint **after** publishing (publish-then-checkpoint). Delivery is therefore **at-least-once**;
a crash re-publishes at most the in-flight event. Consumers are idempotent by event id
(`ConsumedEvents` INSERT-once), making the pipeline **effectively-once**. The transport is a port
(`EventPublisher`) — Postgres LISTEN/NOTIFY today (`PostgresNotifyEventPublisher`), NATS JetStream
swappable without touching the relay.

## Consequences

- ✚ No dual-write window exists at all; the "outbox insert" is the event append itself — one table,
  one transaction, zero duplication of payloads.
- ✚ Kill-and-restart safety is a test (`OutboxRelayTest`), not a claim; the checkpoint semantics are
  ~40 lines anyone can audit.
- ▬ At-least-once means consumers **must** dedupe (they do, by event id) — a new consumer that
  forgets this will double-apply.
- ▬ The relay is a single serial tailer: total order is preserved, but publish throughput is bounded
  by one process (mitigations in the 100x analysis: partition by stream, parallel relays).
- ▬ Publication latency is bounded by the relay poll interval (~1s in the worker), not
  transaction commit.

## Options considered and rejected

- **Dual-write (append + publish in application code).** The precise failure mode this ADR exists
  to eliminate; rejected outright for a money system.
- **A separate `outbox` table written in the same transaction.** The standard pattern for
  state-mutating systems — but here it would duplicate every event payload into a second table and
  introduce a second source of truth to keep in sync with the log. Event sourcing already provides
  an ordered, committed record; a copy adds storage and drift risk for nothing.
- **CDC (Debezium → Kafka) on the events table.** Gives the same guarantee, at the cost of a
  connector fleet, Kafka, and schema-registry operations. Right at large scale; disproportionate
  for this system's footprint, and it would bury the delivery semantics in infrastructure instead
  of 40 auditable lines.
- **Broker-first (publish to NATS, consume to persist).** Inverts the source of truth — the
  database becomes a projection of the broker, retention policy becomes a durability question, and
  optimistic concurrency by stream version is lost.
