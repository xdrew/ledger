# ADR-001 — Event Sourcing for the ledger

Status: Accepted (2026-06-28) · Implemented by `add-event-store-foundation` and every capability on top of it.

## Context

The system's core promise is **correctness of money under concurrency**: no lost updates, no
double-spend, full auditability. Balances are derived facts; the primitive facts are the things
that happened — deposits, holds, postings, settlements. Regulators, support, and reconciliation all
ask "how did this balance come to be?", which a current-state row cannot answer. Concurrent writers
(two transfers racing for the same funds) must be serialized per account without global locks.

## Decision

The write side is **event-sourced**. Every aggregate (Account, JournalEntry, Transfer) is persisted
as an append-only stream of domain events in a single `events` table
(`src/Migrations/LedgerSchemaProvider.php`), keyed by `(stream_type, stream_id, version)` with a
UNIQUE constraint as the **optimistic-concurrency guard** (`DbalEventStore::append` translates the
unique violation into a retriable `ConcurrencyConflict`). A `global_position` BIGINT identity gives
one total order for consumers. State is rebuilt by replaying the stream
(`AggregateRoot::reconstituteFromHistory`); balances and statements are **projections** of the
events, rebuildable at will (ADR-003).

## Consequences

- ✚ The audit log is not a side artifact — it **is** the source of truth; drift between "audit" and
  "state" is impossible by construction.
- ✚ Optimistic per-stream concurrency serializes racing writers without locks; the double-spend
  test is a first-class scenario, not a hope.
- ✚ New read models can be built retroactively from history (projections, ADR-003), and the outbox
  is free (ADR-002).
- ▬ Every state read on the write path replays the stream — acceptable at current stream lengths;
  requires snapshots as streams grow (see the 100x analysis in `docs/design.md`).
- ▬ Events are forever: schema evolution needs versioning/upcasting discipline (ADR-006), and
  developers must think in facts, not updates — a real onboarding cost.
- ▬ Deletion/GDPR-style erasure is harder than `DELETE FROM` (crypto-shredding or payload
  redaction would be needed; out of scope here).

## Options considered and rejected

- **CRUD balances + audit table.** One `balances` table updated in place, plus an `audit_log`
  written alongside. Rejected: the audit trail is advisory — nothing forces it to match the state
  transition (a bug or a manual UPDATE silently desynchronizes them), exactly the failure mode a
  money system cannot have. Concurrency control degenerates to row locks or `SELECT … FOR UPDATE`
  contention on hot accounts.
- **CRUD + CDC-derived audit (e.g. Debezium on the balances table).** Captures *that* a row
  changed, not *why*; intent (hold vs deposit vs settlement) is lost, so reconciliation and
  dispute-handling still lack the business fact. Adds Kafka/Debezium operational surface to a
  system that didn't need it.
- **Event sourcing via a framework (Prooph/EventSauce/Broadway).** The in-house store is ~5 focused
  classes on DBAL; a framework brings its own aggregate lifecycle, serializer, and upgrade
  treadmill for less code than it saves. Rejected for transparency — this system's value is that
  the mechanics are inspectable.
