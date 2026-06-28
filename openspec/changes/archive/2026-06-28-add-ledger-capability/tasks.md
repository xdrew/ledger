> Depends on the archived `event-store` capability. Reads account status from the `accounts`
> context via a port. No new third-party dependencies; no new tables.

## 1. Ledger domain — values

- [x] 1.1 Implement `JournalEntryId` (uuid) and `AccountRef` (opaque account id, decoupled from `Accounts\Domain`).
- [x] 1.2 Implement `LegDirection` (Debit | Credit) and `Leg` (`AccountRef` + direction + positive `Money`, enforced in `Leg::of`).

## 2. Ledger domain — entry & invariants

- [x] 2.1 Implement `JournalEntryPosted` (`DomainEvent`) carrying the entry id and its legs.
- [x] 2.2 Implement domain exceptions (`UnbalancedEntry` incl. too-few-legs, `InvalidLegAmount`, `ClosedAccountPosting`, `JournalEntryNotFound`).
- [x] 2.3 Implement the `JournalEntry` aggregate on `AggregateRoot`: `post(JournalEntryId, Leg...)` enforcing ≥2 legs, balanced-per-currency, and positive leg amounts before recording `JournalEntryPosted`; expose legs; state set only in `apply()`.

## 3. Posting service & account-status port

- [x] 3.1 Define the `AccountStatusReader` port (`assertPostable(AccountRef): void`, throws `ClosedAccountPosting`).
- [x] 3.2 Implement `JournalPostingService`: assert every referenced account is postable via the reader, then build the `JournalEntry`.

## 4. Persistence & wiring

- [x] 4.1 Define the `LedgerRepository` port (`save(JournalEntry, ?EventMetadata)`, `load(JournalEntryId): JournalEntry`).
- [x] 4.2 Implement `EventSourcedLedgerRepository` over `EventStore` (stream type `journal_entry`).
- [x] 4.3 Implement `AccountRepositoryStatusReader` (bridges to accounts: load + reject if closed); add `LedgerEventTypes` registrar. Introduce a composable `EventTypeProvider` mechanism (tagged providers + `EventTypeRegistryConfigurator`), migrate `AccountEventTypes` onto it, and wire the ledger DI (repository + reader + posting service bindings).

## 5. Tests

- [x] 5.1 Aggregate unit tests: balanced two-leg and multi-leg posting; reject <2 legs; reject unbalanced; reject non-positive leg; rehydration.
- [x] 5.2 Posting-service unit test with a stub reader: posting against a closed account is rejected with no event.
- [x] 5.3 Trial-balance property test: post N random balanced entries; assert per-currency global debits == credits and per-account nets reconcile (via the global event read).
- [x] 5.4 Integration test: post → save → reload an entry through `EventSourcedLedgerRepository` (Postgres round-trip).

## 6. Verification & gate

- [x] 6.1 "Done" criterion confirmed: the property-style test posts N random balanced entries and asserts global zero-sum.
- [x] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit (55) + integration (11); `openspec validate add-ledger-capability --strict` passes. (All tests use the `#[Test]` attribute.)
