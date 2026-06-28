## Why

This is the capability the whole system exists for: moving money between accounts **correctly
under concurrency**. A transfer touches three aggregates (source account, destination account,
ledger journal) across separate transactions, so it must be a **saga** — orchestrated steps
with compensation on failure — not a single atomic write. The defining guarantees: insufficient
funds cause no partial effects; a failure after the hold is compensated; two concurrent
transfers from one account with funds for one leave exactly one succeeding; and history is never
edited — a reversal is a new compensating transfer.

## What Changes

- Introduce the **`Transfer`** event-sourced aggregate with states
  `Initiated → Held → Posted → Completed` (happy path) and `→ Failed` (any failure), emitting
  `TransferInitiated`, `TransferHeld`, `TransferPosted`, `TransferCompleted`, `TransferFailed`.
- Introduce a **`TransferOrchestrator`** (process manager) that drives the saga:
  1. **Initiate** — record the transfer.
  2. **Hold** — `source.hold(amount)`; insufficient funds → fail at this step with **no partial
     effects**.
  3. **Post** — post the double-entry journal entry (debit source / credit destination), which
     also rejects closed accounts.
  4. **Settle** — `source.debit(amount)`, `destination.credit(amount)`.
  5. **Complete**. On any failure after the hold → **release the hold** and fail (compensation).
- Enforce **exactly-once under concurrency** via the source account's optimistic-concurrency
  hold: two concurrent transfers race on the source stream version; one wins, the other's hold
  append conflicts and that transfer fails.
- Add **`ReverseTransfer`**: only a `Completed` transfer can be reversed, producing a **new**
  compensating transfer (source/destination swapped, `reversalOf` set) — the original is never
  modified.
- Provide a **`TransferRepository`** (event-sourced, stream type `transfer`) and register the
  transfer event types.

Orchestration is synchronous in this change (the process manager calls the account/ledger
repositories directly). The asynchronous, outbox-driven propagation and command-bus/HTTP entry
points arrive in later changes; the saga logic and compensation are unchanged by that.

## Capabilities

### New Capabilities
- `transfers`: the transfer saga — initiate, hold, post double-entry, settle, complete, with
  compensation on failure, optimistic-concurrency exactly-once, and reversal as a compensating
  transfer.

### Modified Capabilities
<!-- None. Orchestrates the archived accounts and ledger capabilities through their ports. -->

## Impact

- **New code:** `App\Transfers\Domain\` (`Transfer`, `TransferId`, `TransferStatus`, `Event\*`,
  exceptions, `TransferRepository`); `App\Transfers\Application\TransferOrchestrator` (+ the
  `InitiateTransfer` / `ReverseTransfer` inputs); `App\Transfers\Infrastructure\`
  (`EventSourcedTransferRepository`, `TransferEventTypes`).
- **Wiring:** register transfer event types (tagged provider); bind the `TransferRepository`;
  the orchestrator depends on `AccountRepository`, `LedgerRepository`, `JournalPostingService`.
- **Depends on** `accounts`, `ledger`, and `event-store`. No new third-party dependencies; no
  new tables (transfers live as streams in the existing `events` table).
- **Cross-cutting:** carries the §6 concurrency test (two concurrent transfers, one succeeds).
