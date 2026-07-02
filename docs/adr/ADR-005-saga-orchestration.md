# ADR-005 — Saga orchestration (not choreography) for transfers

Status: Accepted (2026-06-28) · Implemented by `add-transfers-saga` (`src/Transfers/Application/TransferOrchestrator.php`).

## Context

A transfer spans three aggregates — source account, destination account, and a journal entry —
each with its own stream and its own optimistic-concurrency boundary. There is no cross-aggregate
transaction (by design: streams are the consistency unit), so the transfer is a saga: a sequence of
local steps with compensation on failure. The question is who drives it: a central coordinator
(orchestration) or event-reacting services (choreography)?

## Decision

A synchronous **orchestrator**. `TransferOrchestrator` drives the fixed step order
**hold → post journal → settle**, recording each stage transition on the `Transfer` aggregate
(Initiated → Held → Posted → Completed/Failed — the saga state *is* an event-sourced aggregate, so
the saga's own history is auditable). The order is chosen so that any failure before settlement is
compensated by exactly one action: **release the hold** — funds sit in the source's reserved bucket
until the journal entry exists and settlement runs, so no partial money movement is ever visible.
Business failures (insufficient funds, closed account) terminate the saga as `failed` with a
reason — an outcome, not an HTTP error; infrastructure races (`ConcurrencyConflict`) fail as
`conflict` and are safely retriable by the client under a new transfer id (or the same idempotency
key at the API layer).

## Consequences

- ✚ The transfer's control flow is one readable method; step order, failure handling, and
  compensation live in a single place with unit tests per branch.
- ✚ The saga state machine rejects illegal transitions (`InvalidTransferTransition`), so a bug
  cannot double-settle or complete an unposted transfer.
- ✚ Compensation is minimal by construction (one action) because the step order was chosen for it.
- ▬ The orchestrator is a synchronous in-process caller today: a crash mid-saga leaves a transfer
  parked in `held`/`posted` (funds reserved, nothing lost). Recovery is currently a runbook play,
  not an automatic timeout/resume — an accepted gap at this scale, noted in `docs/runbook.md`.
- ▬ Best-effort hold release on compensation can itself fail; residuals are reconcilable via the
  ledger trial balance (deliberately swallowed, documented in code).

## Options considered and rejected

- **Choreography (each context reacts to events).** Accounts holds on `TransferInitiated`, ledger
  posts on `FundsHeld`, and so on. The workflow then exists nowhere — it emerges from
  subscriptions, so "why is this transfer stuck?" requires correlating three consumers' logs, and
  changing the step order means coordinating deploys of independent reactors. Compensation paths
  multiply. Rejected: for a fixed, short, correctness-critical workflow, implicitness is pure cost.
- **Two-phase commit across aggregates.** No distributed transaction coordinator exists over
  event streams, and 2PC's blocking failure modes are the classic reason sagas exist. Rejected on
  principle.
- **Process manager reacting to events but with central state (async orchestration).** The right
  evolution once transfers must survive process crashes mid-flight or span services; requires the
  outbox-driven command dispatch and timeout machinery. Deliberately deferred — the synchronous
  orchestrator is transparent and sufficient at current scope, and the saga's event-sourced state
  machine makes the migration mechanical.
