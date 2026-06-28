## Context

A transfer spans three aggregates — source account, destination account, and the ledger
journal — each persisted in its own transaction (separate event streams / the journal). There
is no single ACID write that covers all of them, so a transfer is a **saga**: a sequence of
local steps with explicit compensation. The `Transfers` bounded context is the integrator; it
depends on the `accounts` and `ledger` contexts through their ports.

Constraints from `project.md` and earlier ADRs: event-sourced write side; optimistic
concurrency via the event store; **orchestration over choreography** (ADR-005) — a single
process manager owns the flow, which is far easier to reason about and test than emergent
choreography for a money-movement saga.

## Goals / Non-Goals

**Goals:**
- A `Transfer` aggregate whose state machine encodes the saga lifecycle.
- An orchestrator that drives hold → post → settle → complete with compensation.
- Insufficient funds → fail at hold, **no partial effects**.
- Failure after hold → release the hold, fail.
- Exactly-once when two transfers race on the same source.
- Reversal as a new compensating transfer; the original is immutable.

**Non-Goals (later changes):** asynchronous/outbox-driven step propagation; command-bus and
HTTP entry points; retries/at-least-once delivery; read projections of transfer status.

## Decisions

### D1: `Transfer` is an event-sourced aggregate holding saga state
States: `Initiated → Held → Posted → Completed`, with `→ Failed` reachable from
`Initiated`/`Held`/`Posted`. Events: `TransferInitiated` (source, destination, amount,
optional `reversalOf`), `TransferHeld`, `TransferPosted` (journal entry id), `TransferCompleted`,
`TransferFailed` (reason). Transitions are validated in the aggregate; the orchestrator records
them as each step succeeds/fails. Making the saga an aggregate means its progress is itself
auditable and replayable.

### D2: Synchronous orchestrator (process manager), not choreography
`TransferOrchestrator` calls `AccountRepository`, `LedgerRepository`, and `JournalPostingService`
directly, in order, persisting each aggregate as it goes. This is an orchestrated saga executed
synchronously. When the outbox/async transport lands, the *propagation* of events changes but
the orchestration logic and compensation do not.

- *Alternatives rejected:* choreography (accounts/ledger react to each other's events) — emergent
  control flow is hard to follow and to compensate for a money saga; the brief and ADR-005 pick
  orchestration.

### D3: Step order chosen so compensation only ever has to release the hold
1. **Initiate** → `TransferInitiated`, save. (Initiated)
2. **Hold**: load source, `hold(amount)`, save. Insufficient funds throws *before* any event is
   recorded → no hold persisted → fail (no partial effects). (→ Failed)
3. → `TransferHeld`, save. (Held)
4. **Post** the journal entry (debit source / credit destination) via `JournalPostingService`,
   which rejects closed accounts. A failure here (e.g. destination closed) happens **before any
   balance settlement**, so compensation is simply: release the hold, fail. (→ Failed)
5. → `TransferPosted(journalEntryId)`, save. (Posted)
6. **Settle**: `source.debit(amount)`; `destination.credit(amount)`; save each.
7. → `TransferCompleted`, save. (Completed)

Ordering "post (validate) before settle" means the only compensation the common failure paths
need is **release the hold** — the funds are still wholly in the source's reserved bucket until
step 6. This keeps compensation correct and simple.

### D4: Exactly-once via the source's optimistic-concurrency hold
Two concurrent transfers from the same source both load the source at version V and both pass
the in-memory `available >= amount` check. On save, the first append to the source stream
(expected version V) succeeds; the second (also expecting V) raises `ConcurrencyConflict`. The
orchestrator treats that as a transfer failure (retriable). So at most one hold persists and at
most one transfer completes — the §6 guarantee — with no application-level locking.

### D5: Reversal is a new compensating transfer
`ReverseTransfer(originalId, newId)`: load the original; require it to be `Completed`
(else reject); initiate a new `Transfer` with source/destination **swapped**, same amount, and
`reversalOf = originalId`. The reversal runs the same saga. The original transfer's stream is
never appended to — history is preserved; corrections are forward-only.

### D6: Compensation boundary and the residual settlement window
Compensation covers: insufficient funds (no effect) and any failure between Held and Settle
(release hold). The residual hard case — `source.debit` succeeds but `destination.credit` fails
mid-settle — is minimized by validating postability (closed accounts) in step 4 before any
settlement; if it still occurs, the orchestrator compensates by crediting the source back and
failing. True crash-mid-settle recovery (process dies between debit and credit) is addressed by
the later outbox/retry work and is ultimately reconcilable via the ledger trial balance; noted
as a risk, not solved by a distributed transaction.

## Risks / Trade-offs

- **Crash between `debit` and `credit` leaves an unsettled transfer** → Mitigation: validate
  before settling (D3); idempotent retries + the outbox (later); reversal and the trial-balance
  invariant make it detectable and correctable. Documented in the runbook (drain a stuck saga).
- **A second concurrent transfer fails with a retriable conflict** → Mitigation: that is the
  intended exactly-once behaviour; the conflict surfaces as a retriable failure (HTTP `409` later).
- **Double bookkeeping (account balances + ledger)** → Mitigation: the orchestrator is the single
  writer of both within one transfer; the ledger trial balance cross-checks.

## Open Questions

- Should `TransferFailed` carry a typed reason enum (insufficient funds / conflict / closed
  account) rather than a string? Likely yes; start with a small enum.
- Whether settle should debit-then-credit or credit-then-debit — both have a crash window;
  chosen debit-first so funds never transiently exist in two places. Revisit with the outbox.
