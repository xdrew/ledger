## Context

The write side of `ledger-core` is event-sourced: aggregates emit events, the events are
the source of truth, and aggregate state is rebuilt by replaying them. This change builds
the persistence and rehydration machinery that everything else stands on. It has no
domain semantics of its own — it is pure infrastructure plus a base class — but its
correctness properties (append atomicity, optimistic concurrency, ordering) are the
guarantees the money-correctness story ultimately rests on.

Constraints from `project.md`: hand-rolled store on Doctrine DBAL (no ORM for the write
side), PostgreSQL 18, `declare(strict_types=1)`, PHPStan max, hexagonal layering (the
event-store **port** lives in the application/domain boundary; the DBAL and in-memory
implementations are adapters), injected `Clock` (no `new DateTime()` in domain),
correlation/causation propagation.

## Goals / Non-Goals

**Goals:**
- Append events to a stream atomically with expected-version optimistic concurrency.
- Load (rehydrate) a stream in version order.
- Read all events in a global monotonic order from a given position.
- Serialize/deserialize events with stable type names and a per-event schema version.
- Provide an in-memory adapter behaving identically to the Postgres one.
- Provide a base aggregate root (record / apply / version / rehydrate).

**Non-Goals (deferred to named later changes):**
- Reliable cross-process publication / relay → `add-outbox`.
- Read models / projectors consuming the global stream → `add-projections`.
- Upcasting transformations (the `schema_version` field is reserved now; the upcaster
  mechanism + example land with event-versioning work).
- Snapshots / large-stream optimization (future; called out under Risks).
- Any concrete domain aggregate (`Account`, etc.) → respective capability changes.

## Decisions

### D1: Single `events` table, optimistic concurrency via `UNIQUE (stream_id, version)`
One append-only table holds every event. Concurrency is enforced by a unique constraint
on `(stream_id, version)`: two concurrent appends computing the same next version race on
the insert; exactly one commits, the other hits a unique violation which the adapter
translates into a `ConcurrencyConflict`. The whole append (all events of one call) runs
in one transaction, so a conflict persists nothing.

- *Alternatives rejected:* (a) `SELECT … FOR UPDATE` on a per-stream head row — extra
  round-trip and lock management for no gain over the constraint. (b) Postgres advisory
  locks keyed by stream — works but is stateful, easy to leak, and harder to reason about
  than a declarative constraint. (c) A table per aggregate type — multiplies DDL and
  cross-stream global ordering becomes awkward.

### D2: Global ordering via a `BIGINT GENERATED ALWAYS AS IDENTITY` position
Each row gets a store-wide monotonic `global_position`. Readers page through the store in
`global_position` order from a cursor. This is the seam the outbox and projections read.

- **Known caveat (documented, not solved here):** an identity/sequence value is allocated
  before commit, so under concurrency a row with a *higher* position can become visible
  *before* a lower-positioned row whose transaction has not yet committed. A naive
  "read everything > last_seen" cursor can therefore skip an in-flight event. This is the
  classic event-store ordering gap. For this foundation the global read is used by tests
  and is correct once writers have quiesced; **robust gap-tolerant consumption is an
  explicit responsibility of `add-outbox`** (e.g. tracking in-flight gaps / re-reading,
  or a transactional-outbox table populated in the same tx). We deliberately do not
  over-engineer the store; we record the constraint and address it where it bites.
- *Alternatives rejected:* a separate "commit order" mechanism (e.g. `pg_xact_commit_timestamp`,
  or a second commit-ordered sequence) — meaningful complexity that the outbox solves more
  directly; deferring keeps the foundation minimal.

### D3: `JSONB` payload + `JSONB` metadata, immutable rows
Event payload and metadata are stored as `JSONB` (queryable, debuggable, indexable). Rows
are never updated or deleted — immutability is a property of the store, expressed by the
absence of any update/delete in the port and reinforced by an append-only access pattern.

- *Alternatives rejected:* opaque `BYTEA`/binary blobs — smaller but undebuggable and
  unqueryable, with no real win for this scale; `TEXT` JSON — loses JSONB indexing.

### D4: Event type registry + reserved `schema_version`
Serialization maps a stable string event type (e.g. `accounts.account_opened`) ↔ a PHP
class through a registry, decoupling storage from class names/namespaces. Each event row
carries an integer `schema_version`. No upcasting runs yet, but persisting the version now
means upcasters can be introduced later without a data migration.

- *Alternatives rejected:* serializing FQCN directly — couples stored data to code layout,
  making renames/moves a migration. Omitting `schema_version` now — cheap to add, painful
  to backfill; reserving it is free insurance.

### D5: Port + two adapters
`EventStore` is a port (interface). `DbalEventStore` is the production adapter;
`InMemoryEventStore` is a test adapter implementing the *same* contract, including
optimistic-concurrency rejection and global ordering, so aggregate/saga unit tests run
without a database and still exercise the real concurrency semantics.

### D6: Base aggregate root convention
`AggregateRoot` holds an in-memory list of uncommitted events and a current version. A
protected `recordThat(event)` appends to the uncommitted list and routes the event to an
`apply*`/`when` mutator that changes in-memory state. `pullUncommittedEvents()` returns
and clears the pending list (called by the repository after a successful append).
`reconstituteFromHistory(events)` replays events through the same mutators **without**
re-recording them and sets the version to the last applied event's version.

- *Alternatives rejected:* an external event-applier/reducer separate from the aggregate —
  more ceremony than value for this codebase; the record-then-apply convention is the
  widely understood ES idiom and keeps invariants inside the aggregate.

### D7: Synchronous foundation
This layer is intentionally synchronous: appends and loads are blocking DBAL calls inside
a request or command transaction. The project's async-where-warranted stance applies to
I/O-bound long-running workers (outbox relay, projectors) introduced later — not to the
event-store core, where a single transactional round-trip is correct and simplest.

## Risks / Trade-offs

- **Global-position visibility gap under concurrency (D2)** → Mitigation: documented as a
  store constraint; gap-tolerant / transactional-outbox consumption is owned by
  `add-outbox`. Foundation tests read after writers settle.
- **Unbounded stream growth / replay cost** → Mitigation: out of scope here; snapshotting
  is a future change. Current aggregates (account, transfer) have short streams, so replay
  cost is negligible at portfolio scale; the 100x analysis in `docs/design.md` will revisit.
- **Serialization drift as events evolve** → Mitigation: stable type registry + reserved
  `schema_version` now; upcaster mechanism in a later change.
- **Unique-violation as control flow (D1)** → Mitigation: the adapter catches only the
  specific `(stream_id, version)` unique-violation SQLSTATE and maps it to
  `ConcurrencyConflict`; any other error propagates. Covered by a test.

## Migration Plan

- Add a forward SQL migration creating the `events` table:
  `global_position BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY`, `stream_id`,
  `stream_type`, `version INT`, `event_id` (unique), `event_type`, `schema_version`,
  `payload JSONB`, `metadata JSONB`, `occurred_at TIMESTAMPTZ`, `recorded_at TIMESTAMPTZ`;
  `UNIQUE (stream_id, version)`; index supporting global-ordered reads from a cursor.
- Migrations run as an explicit step (CI / deploy job), **never on app boot** (per the
  deployment principle). For local/test, the migration is applied before integration tests.
- Rollback: the table is new and isolated; the down migration drops it. No data migration,
  no backfill — this is greenfield.

## Open Questions

- Stream identity type: a single opaque `stream_id` (UUID/string) plus a `stream_type`
  discriminator is assumed; whether `stream_type` is strictly needed (vs. encoding it in
  the id) is deferred to first real aggregate use in `add-accounts-capability`.
- Exact correlation/causation metadata shape will be finalized when the message-bus
  middleware that produces it lands (`add-accounts-capability` / `add-http-api`); the store
  treats metadata as an opaque JSON map and is unaffected.
