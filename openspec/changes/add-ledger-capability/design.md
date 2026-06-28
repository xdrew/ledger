## Context

The `Ledger` bounded context holds the immutable double-entry journal: the accounting truth.
It is distinct from `Accounts`, which tracks operational available/reserved balances for
fast decisions (holds). The two are kept consistent by the transfer saga (next change), which
moves money on accounts *and* records the matching journal entry. The ledger's defining
property is reconcilability — the trial-balance invariant — so the design centers on making
balancing structurally enforced and globally verifiable.

Constraints from `project.md`: event-sourced write side; money is integer minor units +
currency (no floats); pure-PHP domain; single-currency accounts (so entries are effectively
single-currency, though the balance rule is expressed per currency to stay general).

## Goals / Non-Goals

**Goals:**
- A `JournalEntry` aggregate that can only be posted balanced (≥2 legs, debits == credits per
  currency, positive amounts) and is immutable thereafter.
- Reject postings that reference a closed account, via a port.
- Event-sourced persistence (one `JournalEntryPosted` per entry stream).
- A property test proving global zero-sum / per-account reconciliation over N random entries.

**Non-Goals (later):** the transfer saga, reversals/compensating entries, the balances/
statement read model, command handlers/HTTP, idempotency, cross-currency exchange.

## Decisions

### D1: Per-entry aggregate, single immutable event
Each journal entry is its own aggregate (stream type `journal_entry`, stream id = entry id)
emitting exactly one `JournalEntryPosted`. Immutability falls out naturally: a posted entry's
stream never gets a second event. Optimistic concurrency is trivial (always a new stream at
expected version 0). Corrections are *new* compensating entries, never amendments.

- *Alternatives rejected:* a single global `Journal` stream that all entries append to — one
  hot stream, a concurrency bottleneck, and needless coupling between unrelated entries.

### D2: Legs as (account, direction, positive amount); balance checked per currency
A `Leg` is an `AccountRef` + `LegDirection` (Debit | Credit) + a positive `Money`. An entry is
balanced when, for every currency present, the sum of debit amounts equals the sum of credit
amounts. Using an explicit direction + positive amount (rather than signed money) keeps the
accounting intent legible and makes "amount must be positive" a simple per-leg rule.

- *Alternatives rejected:* signed `Money` (a negative amount is meaningless as "money" and
  blurs debit/credit intent); a single debit + single credit only (real entries — fees,
  splits — need N legs).

### D3: Structural invariants in the aggregate; closed-account check in a posting service
The `JournalEntry::post()` factory enforces the *structural* invariants (≥2 legs, balanced,
positive) using only its own data. The *contextual* invariant — no closed account — needs
external state, so it lives in a `JournalPostingService` that consults an `AccountStatusReader`
port before constructing the entry. This keeps the aggregate pure and replay-safe while still
enforcing the rule at the one place postings are created.

- *Alternatives rejected:* calling the reader from inside the aggregate factory (aggregates
  shouldn't depend on services); skipping the check and trusting callers (loses defense-in-depth
  the brief asks for).

### D4: Decouple from Accounts via `AccountRef` + `AccountStatusReader`
The ledger does not import `Accounts\Domain`. Legs reference accounts by `AccountRef` (a uuid
string VO). `AccountStatusReader::assertPostable(AccountRef)` is a ledger-domain port; an
infrastructure adapter implements it by bridging to the accounts context (today: load the
`Account` and check it isn't closed; later: read the account-status projection, avoiding an
aggregate load on the hot path).

- *Alternatives rejected:* moving `AccountId` into the shared kernel and depending on it
  directly — tighter coupling than necessary; the journal only needs an opaque reference.

### D5: Trial balance computed from the global event stream
Reconciliation reads `JournalEntryPosted` events (via the event store's global read) and sums
legs: per account, debits − credits; per currency, the global net. Because every entry is
balanced, the global net is zero by construction — the property test asserts the machinery
preserves this over N random entries and that per-account sums reconcile to the global zero.

## Risks / Trade-offs

- **Double bookkeeping (Account balances vs ledger journal) could drift** → Mitigation: the
  transfer saga is the single writer that updates both atomically-in-intent; the ledger is the
  audit truth and the trial balance is the cross-check. Documented relationship.
- **Loading a full Account just to check "closed" is heavy** → Mitigation: behind the
  `AccountStatusReader` port; swap to the account-status projection once `add-projections` lands.
- **Multi-currency entries are allowed by the balance rule but accounts are single-currency**
  → Mitigation: the rule is per-currency and correct generally; in practice entries are
  single-currency. No cross-currency exchange is introduced.

## Open Questions

- Should an entry carry a typed `reference` (e.g. the originating transfer id) beyond a free
  string? Deferred until the transfer saga defines what it needs to correlate.
- Exact `AccountStatusReader` adapter source (aggregate vs projection) is settled when
  `add-projections` exists; the port keeps that decision out of the domain.
