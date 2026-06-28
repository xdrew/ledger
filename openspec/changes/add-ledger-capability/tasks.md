> Depends on the archived `event-store` capability. Reads account status from the `accounts`
> context via a port. No new third-party dependencies; no new tables.

## 1. Ledger domain — values

- [ ] 1.1 Implement `JournalEntryId` (uuid) and `AccountRef` (opaque account id, decoupled from `Accounts\Domain`).
- [ ] 1.2 Implement `LegDirection` (Debit | Credit) and `Leg` (`AccountRef` + direction + positive `Money`).

## 2. Ledger domain — entry & invariants

- [ ] 2.1 Implement `JournalEntryPosted` (`DomainEvent`) carrying the entry id and its legs.
- [ ] 2.2 Implement domain exceptions (`UnbalancedEntry` incl. too-few-legs, `InvalidLegAmount`, `ClosedAccountPosting`).
- [ ] 2.3 Implement the `JournalEntry` aggregate on `AggregateRoot`: `post(JournalEntryId, Leg...)` enforcing ≥2 legs, balanced-per-currency, and positive leg amounts before recording `JournalEntryPosted`; expose legs; state set only in `apply()`.

## 3. Posting service & account-status port

- [ ] 3.1 Define the `AccountStatusReader` port (`assertPostable(AccountRef): void`, throws `ClosedAccountPosting`).
- [ ] 3.2 Implement `JournalPostingService`: assert every referenced account is postable via the reader, then build the `JournalEntry`.

## 4. Persistence & wiring

- [ ] 4.1 Define the `LedgerRepository` port (`save(JournalEntry, ?EventMetadata)`, `load(JournalEntryId): JournalEntry`).
- [ ] 4.2 Implement `EventSourcedLedgerRepository` over `EventStore` (stream type `journal_entry`).
- [ ] 4.3 Implement the `AccountStatusReader` adapter bridging to the accounts context (load account, reject if closed); add `LedgerEventTypes` registrar and wire DI (event type, repository + reader bindings).

## 5. Tests

- [ ] 5.1 Aggregate unit tests: balanced two-leg and multi-leg posting; reject <2 legs; reject unbalanced; reject non-positive leg; immutability (single event); rehydration.
- [ ] 5.2 Posting-service unit test with a stub reader: posting against a closed account is rejected with no event.
- [ ] 5.3 Trial-balance property test: post N deterministically-generated random balanced entries; assert per-currency global debits == credits and per-account nets reconcile (via the global event read).
- [ ] 5.4 Integration test: post → save → reload an entry through `EventSourcedLedgerRepository` (Postgres round-trip).

## 6. Verification & gate

- [ ] 6.1 Confirm the "done" criterion: the property-style test posts N random balanced entries and asserts global zero-sum.
- [ ] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration suites; `openspec validate add-ledger-capability --strict` passes.
