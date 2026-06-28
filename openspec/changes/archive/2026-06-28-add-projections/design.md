## Context

CQRS: commands go through event-sourced aggregates; queries read from projections. This change
builds the read side — `account_balances` and `account_statement` — folded from the account
domain events and rebuildable from the event store. Read models are derived state with no audit
requirement of their own, so they are plain mutable tables (ADR-004), the deliberate
counterpoint to the event-sourced write side.

Constraints from `project.md`: projectors must be **replay-safe and side-effect-free except
through ports** (§6 determinism); read models must be **fully rebuildable** (§5).

## Goals / Non-Goals

**Goals:**
- `account_balances` and `account_statement` read models projected from account events.
- A catch-up runner that is exactly-once and replay-safe.
- `projections:rebuild` producing state identical to a live projection.
- A projection-lag measure.
- Query services to read the models.

**Non-Goals (later changes):** the asynchronous outbox transport that pushes events to the
projectors (`add-outbox`); the Prometheus export of `projection_lag_seconds`
(`add-observability`); HTTP endpoints (`add-http-api`); transfer/ledger read models (added when
needed).

## Decisions

### D1: Plain mutable read-model tables
`account_balances` keyed by `account_id` (currency, available, reserved, total as BIGINT minor
units, the source stream `version`). `account_statement` is an append-only-per-account log of
postings/holds ordered by `global_position`, with `UNIQUE (global_position)` so re-inserts are
no-ops. `projection_checkpoints` holds the runner's last processed `global_position`. These are
derived and disposable — never the source of truth.

### D2: Catch-up runner; checkpoint advances in the same transaction as the writes
`ProjectionRunner` reads the event store's global stream from `checkpoint+1` in batches; for each
batch it opens one transaction, applies every event to the projectors, advances the checkpoint to
the last position, and commits. Because the read-model writes and the checkpoint move atomically,
a crash mid-batch rolls back both — so re-processing resumes cleanly and never double-counts. This
gives **exactly-once** effect even though incremental balance updates are not naturally idempotent.

- *Alternatives rejected:* dedupe by event id with a processed-ids table — more storage and a
  second write; the atomic checkpoint is simpler and sufficient. (When the outbox delivers
  at-least-once in the next change, the same atomic-checkpoint guard still holds, and the
  statement's `UNIQUE(global_position)` is a second line of defence.)

### D3: Projectors are pure folds over events through a write port
Each projector declares which events it handles and applies them via a read-model write port
(DBAL). No other side effects (determinism §6). `AccountBalancesProjector`:
`AccountOpened` → upsert zero row; `FundsDeposited`/`FundsCredited` → available/total +=;
`FundsHeld` → available -=, reserved +=; `HoldReleased` → reserved -=, available +=;
`FundsDebited` → reserved -=, total -=; each sets `version` to the event's stream version.
`AccountStatementProjector` inserts one ordered row per posting/hold event.

### D4: Rebuild = truncate + reset checkpoint + replay
`projections:rebuild` truncates the read-model tables, resets the checkpoint to 0, and runs the
catch-up to head. Because projection is a deterministic fold of the same ordered event history,
the rebuilt state is identical to the live one — the change's definition of done.

### D5: Projection lag = head − checkpoint
Lag is the number of global positions between the latest stored event and the runner's checkpoint
(`projections:status` prints it). The time-based `projection_lag_seconds` Prometheus gauge is
wired in `add-observability`; this change exposes the underlying measure.

### D6: Projectors consume typed domain events (read-direction coupling)
Projectors `instanceof`-match the account event classes, so `projections` depends on
`Accounts\Domain\Event` (a read-only dependency on already-published events). Transfer/ledger
read models, when needed, add their own projectors the same way.

## Risks / Trade-offs

- **Incremental balance updates are not idempotent under naive re-delivery** → Mitigation: the
  atomic checkpoint (D2) makes processing exactly-once; `UNIQUE(global_position)` guards the
  statement. Revisited when the outbox delivers at-least-once.
- **Global-position visibility gap (from the event-store foundation) could skip an in-flight
  event** → Mitigation: the runner only advances past contiguous, committed positions; the
  outbox change owns robust gap-tolerant consumption. For now the runner reads after writers
  settle and rebuild is authoritative.
- **A large event history makes rebuild slow** → Mitigation: batched reads; acceptable at
  portfolio scale; snapshots are a future option.

## Open Questions

- One shared checkpoint for all projectors vs. per-projector checkpoints — start with one runner
  and one checkpoint (single lag figure); split if projectors need independent progress.
- Statement granularity (one row per event vs. enriched with transfer context) — start with one
  row per account posting/hold; enrich when the statement endpoint defines its shape.
