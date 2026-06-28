## Why

Every write-side bounded context (`Accounts`, `Ledger`, `Transfers`) is event-sourced,
so nothing domain-related can be built until there is an append-only event store with
optimistic concurrency and a base aggregate root to record and replay events. This is
the load-bearing foundation the entire write side depends on; it must exist and be
proven correct before any aggregate is written.

## What Changes

- Introduce an **append-only event store** persisting domain events into per-aggregate
  **streams** on PostgreSQL (via Doctrine DBAL, raw SQL — no ORM).
- **Per-stream sequential versioning** and **optimistic concurrency**: appends declare an
  expected version and are rejected atomically on a stale version (concurrency conflict).
- **Global ordering**: every persisted event receives a globally monotonic position, and
  the store can be read in global order from a given position (the read path that the
  later outbox and projections will consume).
- **Event serialization** with a type registry and event **metadata** (event id, event
  type, schema version, occurred-at from an injected `Clock`, correlation/causation ids).
  The schema-version field is reserved now to enable upcasting in a later change.
- An **in-memory event store** test double satisfying the identical contract.
- A **base aggregate root**: records uncommitted events, applies them to mutate state,
  tracks version, and rehydrates from a historical stream.
- A SQL **migration** creating the `events` table (DDL only; not run on app boot).

## Capabilities

### New Capabilities
- `event-store`: append-only, stream-based event persistence with per-stream versioning,
  optimistic concurrency, global ordering, event serialization + metadata, an in-memory
  test double, and the base aggregate root used by all write-side aggregates.

### Modified Capabilities
<!-- None — this is the first change; no existing specs. -->

## Impact

- **New code (no behavior change to anything existing — greenfield):**
  - Shared kernel: `DomainEvent` interface, recorded-event envelope, `EventId`, stream
    identifiers, `Clock` port.
  - `EventStore` port + PostgreSQL (DBAL) adapter + in-memory adapter.
  - Event serializer + type registry.
  - `AggregateRoot` base class.
- **Database:** new `events` table (+ indexes / uniqueness constraint); a migration class
  under the skeleton's migrations mechanism.
- **Depends on `add-project-skeleton`:** reuses its PHP 8.5 / Symfony 8 project, DBAL
  connection service, migrations mechanism, docker-compose Postgres 18, test harness, and
  CI gate. This change adds no project scaffolding of its own.
- **Dependencies:** Doctrine DBAL, PostgreSQL 18 (both from the skeleton). No ORM. No
  message bus yet (the `thesis/message-bus` dispatch and the transactional outbox arrive in
  later changes).
- **Downstream:** unblocks `add-accounts-capability`, `add-ledger-capability`,
  `add-transfers-saga`, and (via the global read path) `add-outbox` / `add-projections`.
