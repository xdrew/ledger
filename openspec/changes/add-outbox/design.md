## Context

Consumers (projectors now; external systems later) need domain events reliably and in order,
decoupled from the write path. The event store already persists events durably and in global
order within the aggregate's append transaction — so the durable, atomic "outbox write" is
already done. What's missing is the **relay**: a worker that reads the log and publishes events
to a transport, surviving crashes without losing or silently duplicating effects.

Constraints from the brief: at-least-once delivery; consumers idempotent by event id; transport
abstracted (Postgres `LISTEN/NOTIFY` acceptable now, NATS JetStream swappable later); the relay
is a worker (RoadRunner job in production).

## Goals / Non-Goals

**Goals:**
- Publish every recorded event at least once, in global order, via a transport port.
- Crash-safe relay: a kill mid-batch loses no events and double-delivers none observably.
- Idempotent consumers (by event id).
- A console relay worker and a checkpoint.

**Non-Goals (later):** the RoadRunner job host and a fiber-based relay loop (deployment); the
NATS JetStream transport (a port swap-in); `outbox_pending` metric export (observability);
wiring the projectors to be relay-driven rather than polled (kept independent for now).

## Decisions

### D1: The event log is the outbox (ADR-002)
The event store writes events in the aggregate's append transaction, so the `events` table is a
durable, ordered, atomic record of everything that happened — exactly what an outbox provides.
The relay tails it from a published checkpoint. This avoids a second "outbox" table and the
dual-write it would imply inside our own database.

- *Alternatives rejected:* a separate `outbox` table written alongside `events` (dual-write of
  the same facts, redundant given the log); CDC/Debezium (heavy external infra for a single DB).

### D2: Transport behind an `EventPublisher` port
`EventPublisher::publish(RecordedEvent)` is the seam. Adapters: `InMemoryEventPublisher`
(records published events for tests); `PostgresNotifyEventPublisher` (`SELECT pg_notify(channel,
payload)` with the event id + global position so `LISTEN`ing consumers can react). NATS JetStream
via fiber-based `thesis/nats` is a future adapter — the relay and consumers don't change.

### D3: At-least-once via publish-then-checkpoint, per event
For each event after the checkpoint, in global order: publish, then advance the checkpoint. The
checkpoint advances **only after** a successful publish, so no event is ever skipped (no loss).
If the process dies between publish and checkpoint, the event is re-published on restart — so
delivery is at-least-once with a one-event re-delivery window.

- *Alternatives rejected:* checkpoint-before-publish (could lose an event on crash); a single
  batch checkpoint (larger re-delivery window on crash).

### D4: Idempotent consumers by event id
A `consumed_events (consumer, event_id)` guard lets each consumer record what it has applied and
skip re-deliveries, turning at-least-once into effectively-once per consumer. The projection
runner is already idempotent (its position checkpoint), so it needs no change; the guard is for
consumers that act per event.

### D5: Global-position visibility gap
Because `global_position` is an identity assigned before commit, a transaction with a higher
position can become visible before a lower one commits — a naive "read > checkpoint" relay could
skip the lower one. **Implemented now:** the relay reads strictly in ascending order from the
checkpoint and publishes each event, which is correct for the short single-transaction appends
this system makes (the window for an out-of-order commit is tiny). **Deferred as the hardening /
scale path** (documented, not built here): a gap-aware cursor that stops at a missing expected
position until it appears or a settle window elapses, or — robustly — recording each row's
transaction id and relaying only below the snapshot's `xmin`. The change's DoD is crash recovery,
which is fully covered; the gap hardening lands when throughput/concurrency demands it.

### D6: Relay is a console worker now
`outbox:relay` runs one catch-up pass (`--once`) or loops with a short sleep. Production runs it
as a RoadRunner job (deployment) and, where it pays off, as a fiber-based non-blocking loop with
the NATS transport (the project's async-where-warranted stance). The relay class is transport-
and host-agnostic.

## Risks / Trade-offs

- **At-least-once means duplicates on the wire** → Mitigation: idempotent consumers (D4); the
  test asserts no observable double-effect.
- **Visibility-gap skip under high concurrency** → Mitigation: not triggered by today's short
  single-transaction appends; the gap-aware cursor / xmin-based relay is the documented hardening
  path (D5).
- **Reusing the event log as the outbox couples publication to the store** → Mitigation:
  acceptable — the store is already the system's ordered log of truth; the transport port keeps
  downstream decoupled.

## Open Questions

- Settle-window duration vs. the xmin approach — start with a small configurable window; revisit
  under load.
- Whether projectors should become relay-driven now or stay polled — kept polled this change to
  limit blast radius; revisit when external consumers exist.
