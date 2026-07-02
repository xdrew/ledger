# ADR-003 — Asynchronous projections; eventual consistency on reads

Status: Accepted (2026-06-28) · Implemented by `add-projections` (`src/Projections/`).

## Context

Reads (balance, statement) must be cheap and must not contend with the write path. Replaying an
account's stream on every `GET` couples read cost to history length; querying the write side for
lists (statements) is worse. But maintaining read models raises the question every CQRS system must
answer explicitly: are reads synchronous with writes, or eventually consistent — and who pays?

## Decision

Projections are **asynchronous, checkpointed catch-up subscriptions**. `ProjectionRunner` reads the
global stream from a checkpoint and folds events into plain tables (`account_balances`,
`account_statement`), advancing the checkpoint **in the same database transaction** as the
read-model writes — so projection processing is exactly-once and replay-safe even though the
updates are incremental. Read models are **disposable**: `projections:rebuild` truncates and
replays from position zero, and an invariant test asserts a rebuild equals the live projection.
The API reads projections only (`AccountBalanceView`, `AccountStatementView`) and exposes the
projection `version` on balance responses so clients can detect staleness. HTTP/e2e tests catch up
projections explicitly rather than sleeping.

## Consequences

- ✚ Reads are O(1) table lookups regardless of history length; the write path never blocks on
  read-model maintenance.
- ✚ Rebuildability makes read-model bugs cheap: fix the projector, rebuild, done — no migration of
  derived data (see the runbook play).
- ✚ Checkpoint-in-same-transaction gives exactly-once folding with no idempotency bookkeeping in
  projectors themselves.
- ▬ **Read-after-write is not guaranteed**: a client that writes and immediately reads may see the
  previous state. Exposed honestly via `version` + `projection_lag_seconds`; the SLO caps the lag
  (30s alert threshold, typically ~1s).
- ▬ Operating burden: lag is a first-class metric that must be watched (alert `ProjectionLagHigh`),
  and the worker is a deployment unit of its own.

## Options considered and rejected

- **Synchronous projections (update read models in the write transaction).** Restores
  read-after-write but couples write latency and availability to every read model — adding a
  projection slows every command, and a projector bug breaks writes. The wrong trade for a ledger
  whose writes are the money path.
- **Read from the write side (replay streams per query).** Correct and simple, but per-read cost
  grows with history and statements need cross-stream queries the event store can't serve without…
  building projections. Acceptable only as a stopgap.
- **Snapshot-on-read caching.** A cache with invalidation questions instead of a projection with a
  checkpoint; harder to reason about staleness, no rebuild story.
- **Blocking reads until caught up ("read-your-writes" middleware).** Turns worst-case projection
  lag into worst-case request latency and adds coordination; the honest `version` field costs less
  and lets clients that care poll for it.
