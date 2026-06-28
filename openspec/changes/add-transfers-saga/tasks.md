> Depends on the archived `accounts`, `ledger`, and `event-store` capabilities. No new
> third-party dependencies; no new tables (transfers are streams in the `events` table).

## 1. Transfer domain

- [ ] 1.1 Implement `TransferId` (uuid) and `TransferStatus` (Initiated | Held | Posted | Completed | Failed).
- [ ] 1.2 Implement the events under `App\Transfers\Domain\Event` (`TransferInitiated` with source/destination/amount/optional `reversalOf`, `TransferHeld`, `TransferPosted` with journal entry id, `TransferCompleted`, `TransferFailed` with reason).
- [ ] 1.3 Implement domain exceptions (`InvalidTransferTransition`, `TransferNotReversible`, `TransferNotFound`) and a `FailureReason` enum (insufficient funds / closed account / conflict / other).
- [ ] 1.4 Implement the `Transfer` aggregate on `AggregateRoot`: `initiate`, `markHeld`, `markPosted`, `complete`, `fail`; enforce valid transitions; state set only in `apply()`.

## 2. Persistence & wiring

- [ ] 2.1 Define the `TransferRepository` port (`save`, `load(TransferId): Transfer`).
- [ ] 2.2 Implement `EventSourcedTransferRepository` over `EventStore` (stream type `transfer`).
- [ ] 2.3 Add `TransferEventTypes` (tagged `EventTypeProvider`) and bind the `TransferRepository`.

## 3. Orchestration

- [ ] 3.1 Implement `TransferOrchestrator::initiate(InitiateTransfer)`: initiate → hold (insufficient → fail, no effects) → mark held → post journal entry (closed account → compensate) → mark posted → settle (debit source, credit destination) → complete; compensation releases the hold and fails on any post-hold error (incl. `ConcurrencyConflict`).
- [ ] 3.2 Implement `TransferOrchestrator::reverse(ReverseTransfer)`: load original; require `Completed`; initiate a new compensating transfer (swapped source/destination, `reversalOf` set) and run it; never modify the original.

## 4. Tests

- [ ] 4.1 Saga unit test — happy path: a funded transfer completes; source/destination balances move; a balanced journal entry is posted (in-memory event store + real account/ledger repositories).
- [ ] 4.2 Saga unit test — insufficient funds: fails at hold with no partial effects (no hold, no journal entry, balances unchanged).
- [ ] 4.3 Saga unit test — failure after hold: closed destination → posting fails → hold released → `Failed`, source restored.
- [ ] 4.4 Saga unit test — state machine: invalid transition (e.g. complete before post) is rejected.
- [ ] 4.5 Saga unit test — reversal: reversing a completed transfer creates a swapped compensating transfer referencing the original; reversing a non-completed transfer is rejected.
- [ ] 4.6 Concurrency test: two transfers from the same source with funds for one (racing on the source stream version) → exactly one `Completed`, one `Failed`, source debited once. Include an integration variant against PostgreSQL.

## 5. Verification & gate

- [ ] 5.1 Confirm the "done" criteria: happy path, insufficient funds, failure-after-hold compensation, reversal, and exactly-once concurrency.
- [ ] 5.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration suites; `openspec validate add-transfers-saga --strict` passes.
