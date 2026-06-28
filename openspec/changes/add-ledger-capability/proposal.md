## Why

Accounts track operational balances, but the system needs an immutable, auditable
**double-entry journal** — the accounting source of truth where every money movement is
recorded as balanced debits and credits. This is what makes the ledger reconcilable: at any
time, across all entries, every account's debits minus credits nets out and the whole system
sums to zero per currency. The transfer saga (next change) will post to this journal.

## What Changes

- Introduce the **`JournalEntry`** aggregate: a posting of **two or more legs**, each a
  debit or credit of a positive amount on an account, emitting a single immutable
  **`JournalEntryPosted`** event.
- Enforce invariants when posting: at least two legs; **balanced per currency** (total
  debits equal total credits for each currency in the entry); every leg amount strictly
  positive; **no posting that references a closed account**.
- Entries are **immutable** — once posted they are never amended (corrections are new
  compensating entries, introduced with reversals later).
- Provide a **`LedgerRepository`** (event-sourced, stream type `journal_entry`) and register
  the journal event type.
- Establish the **trial-balance invariant** as a property test: posting N random balanced
  entries leaves every account reconciled and the system at global zero-sum per currency.

To keep the bounded contexts decoupled, the ledger references accounts by a lightweight
`AccountRef` (id only) and checks postability through an **`AccountStatusReader`** port,
implemented by an adapter that bridges to the accounts context.

Out of scope (later changes): the transfer saga that drives postings, reversals/compensation,
read projections (a balances/statement read model — the closed-account check will move onto
it once it exists), command handlers/HTTP, idempotency.

## Capabilities

### New Capabilities
- `ledger`: the immutable double-entry journal — post balanced multi-leg entries, enforce
  the balancing and closed-account invariants, persist entries as event streams, and keep a
  globally reconcilable (zero-sum) record.

### Modified Capabilities
<!-- None. Builds on the archived `event-store`; reads account status through a port. -->

## Impact

- **New code:** `App\Ledger\Domain\` (`JournalEntry`, `JournalEntryId`, `Leg`,
  `LegDirection`, `AccountRef`, `Event\JournalEntryPosted`, exceptions, `LedgerRepository`,
  `AccountStatusReader`, a `JournalPostingService`); `App\Ledger\Infrastructure\`
  (`EventSourcedLedgerRepository`, `LedgerEventTypes`, an `AccountStatusReader` adapter over
  the accounts context).
- **Wiring:** register the journal event type; bind the `LedgerRepository` and
  `AccountStatusReader` ports.
- **Depends on** the `event-store` capability; reads account status from the `accounts`
  context via the adapter. No new third-party dependencies; no new tables (journal entries
  live as streams in the existing `events` table).
