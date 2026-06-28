> Depends on the archived `accounts`, `ledger`, and `event-store` capabilities. No new
> third-party dependencies; no new tables (transfers are streams in the `events` table).

## 1. Transfer domain

- [x] 1.1 Implement `TransferId` (uuid) and `TransferStatus` (Initiated | Held | Posted | Completed | Failed).
- [x] 1.2 Implement the events under `App\Transfers\Domain\Event` (`TransferInitiated` with source/destination/amount/optional `reversalOf`, `TransferHeld`, `TransferPosted` with journal entry id, `TransferCompleted`, `TransferFailed` with reason).
- [x] 1.3 Implement domain exceptions (`InvalidTransferTransition`, `TransferNotReversible`, `TransferNotFound`) and a `FailureReason` enum (insufficient funds / closed account / conflict / other).
- [x] 1.4 Implement the `Transfer` aggregate on `AggregateRoot`: `initiate`, `markHeld`, `markPosted`, `complete`, `fail`; enforce valid transitions; state set only in `apply()`.

## 2. Persistence & wiring

- [x] 2.1 Define the `TransferRepository` port (`save`, `load(TransferId): Transfer`).
- [x] 2.2 Implement `EventSourcedTransferRepository` over `EventStore` (stream type `transfer`).
- [x] 2.3 Add `TransferEventTypes` (tagged `EventTypeProvider`) and bind the `TransferRepository`.

## 3. Orchestration

- [x] 3.1 Implement `TransferOrchestrator::initiate(InitiateTransfer)`: initiate → hold (insufficient → fail, no effects) → mark held → post journal entry (closed account → compensate) → mark posted → settle (debit source, credit destination) → complete; compensation releases the hold and fails on any post-hold error (incl. `ConcurrencyConflict`).
- [x] 3.2 Implement `TransferOrchestrator::reverse(ReverseTransfer)`: load original; require `Completed`; initiate a new compensating transfer (swapped source/destination, `reversalOf` set) and run it; never modify the original.

## 4. Tests

- [x] 4.1 Saga unit test — happy path: a funded transfer completes; balances move; a balanced journal entry is posted (in-memory event store + real account/ledger repositories).
- [x] 4.2 Saga unit test — insufficient funds: fails at hold with no partial effects.
- [x] 4.3 Saga unit test — failure after hold: closed destination → posting fails → hold released → `Failed`, source restored.
- [x] 4.4 Aggregate unit test — state machine: invalid transitions (complete before post, re-hold, fail a completed transfer) rejected.
- [x] 4.5 Saga unit test — reversal: reversing a completed transfer creates a swapped compensating transfer referencing the original (original unchanged); reversing a non-completed transfer is rejected.
- [x] 4.6 Exactly-once: two transfers from one source with funds for one → exactly one `Completed`, source debited once (unit + PostgreSQL integration); plus a lost-race test where the source hold append conflicts → `Failed(conflict)` with no effects.

## 5. Verification & gate

- [x] 5.1 "Done" criteria confirmed: happy path, insufficient funds, failure-after-hold compensation, reversal, and exactly-once concurrency.
- [x] 5.2 Green: php-cs-fixer (phpyh), PHPStan max, unit (73) + integration (17); `openspec validate add-transfers-saga --strict` passes.
