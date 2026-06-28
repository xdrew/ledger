## Why

Queries must not touch the event-sourced write side (CQRS). Reading a balance by replaying an
account's whole stream, or building a statement by scanning all events, does not scale and
couples reads to write internals. The system needs **read models** — `account_balances` and
`account_statement` — kept up to date by projecting domain events, and **fully rebuildable**
from the event store so they are never a separate source of truth.

Like the idempotency store, read models are deliberately **plain mutable tables, not
event-sourced** (ADR-004): they are derived, disposable, and rebuildable.

## What Changes

- Add read-model tables (plain, mutable): **`account_balances`** (account_id, currency,
  available, reserved, total, version) and **`account_statement`** (per-account, ordered
  postings/holds), plus a **`projection_checkpoints`** table.
- Add **projectors** that fold account events into those read models: an
  `AccountBalancesProjector` and an `AccountStatementProjector`.
- Add a **`ProjectionRunner`** that reads the event store's global stream from a checkpoint,
  applies events to the projectors, and advances the checkpoint **in the same transaction as
  the read-model writes** — exactly-once and replay-safe.
- Add console commands: **`projections:rebuild`** (truncate read models + reset checkpoint +
  replay from position 0 → identical to a live projection), **`projections:run`** (catch up
  from the checkpoint), and **`projections:status`** (show projection lag).
- Compute **projection lag** = latest global position − checkpoint position (exposed now via
  `projections:status`; the Prometheus metric wiring lands in `add-observability`).
- Add read query services for balances and statement (for the HTTP API later).

The projectors are driven by **polling the global event stream** in this change; the
**asynchronous, outbox-driven** transport (`LISTEN/NOTIFY`) that pushes events to them arrives
in `add-outbox`. The projector logic is unchanged by that.

## Capabilities

### New Capabilities
- `projections`: async, rebuildable read models (`account_balances`, `account_statement`)
  projected from domain events, with exactly-once catch-up, a `projections:rebuild` command,
  and a projection-lag measure.

### Modified Capabilities
<!-- None. Reads account events from the event store; read models are plain tables. -->

## Impact

- **New code:** `App\Projections\` — the read-model write port + DBAL projectors, the
  `ProjectionRunner` + `CheckpointStore`, the console commands, lag computation, and read
  query services (`AccountBalanceView`, `AccountStatementView`).
- **Database:** new `account_balances`, `account_statement`, `projection_checkpoints` tables
  (a migration) — plain mutable read models, **not** in the event store.
- **Depends on** `event-store` (global read) and the `accounts` event types. No new
  third-party dependencies.
- **Downstream:** `add-outbox` drives the projectors asynchronously; `add-http-api` serves
  `GET /accounts/{id}` and `/statement` from these read models; `add-observability` exports
  `projection_lag_seconds`.
