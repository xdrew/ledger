## Context

`Account` is the first real aggregate. It sits in the `Accounts` bounded context with zero
framework dependencies and is built on the archived event-store foundation
(`AggregateRoot`, `DomainEvent`, `EventStore`, the serializer registry). The aggregate is
where money-safety invariants are enforced — this is the whole point of the project — so
the design prioritizes making illegal states unrepresentable and every invariant unit-testable.

Constraints from `project.md`: money is integer minor units + currency, never floats;
single-currency accounts; the domain is pure PHP (no Symfony) and time comes from the
injected `Clock`; optimistic concurrency is handled by the event store.

## Goals / Non-Goals

**Goals:**
- A `Money` value object (integer minor units + `Currency`) with safe arithmetic that
  refuses cross-currency operations and never uses floats.
- An `Account` aggregate enforcing: active-only mutations, non-negative available, reserved
  in `[0, total]`, strictly positive amounts, currency consistency.
- The eight account events, each (de)serializable via the registry.
- An event-sourced `AccountRepository` so accounts persist as `account` streams.
- Unit tests covering every invariant (the change's definition of done).

**Non-Goals (later changes):** command handlers / message bus, HTTP, idempotency, the
ledger journal, transfer orchestration, and event upcasting.

## Decisions

### D1: `Money` = integer minor units + `Currency`, no floats
`Money` holds an `int $minorUnits` and a `Currency`. Arithmetic (`add`, `subtract`) requires
matching currencies (else `CurrencyMismatch`); comparisons (`isNegative`,
`greaterThanOrEqual`, etc.) likewise. `Currency` validates an ISO-4217-style 3-letter
uppercase code. No floating point anywhere.

- *Alternatives rejected:* a float/decimal-string money (precision/΅rounding hazards in a
  ledger); pulling a money library (moneyphp/money) — fine in general, but a small in-house
  VO keeps the shared kernel dependency-free and the rounding rules explicit; revisit if
  multi-currency/allocation needs grow.

### D2: Balance model — available, reserved, derived total
The account tracks `available` and `reserved` (both `Money`); `total = available + reserved`.
This directly expresses the brief's "available vs reserved balance" and makes the hold
lifecycle a movement between the two buckets.

- Operations and their effects:
  - **deposit(amount):** `available += amount` (external funds in).
  - **hold(amount):** `available -= amount; reserved += amount` (requires `available ≥ amount`).
  - **releaseHold(amount):** `reserved -= amount; available += amount` (requires `reserved ≥ amount`).
  - **debit(amount):** `reserved -= amount` (settle held funds out of the account).
  - **credit(amount):** `available += amount` (funds arrive).
- *Alternatives rejected:* a single balance with separate "holds" ledger — more moving parts
  than the two-bucket model needs at this layer; the journal (`add-ledger-capability`) is the
  authoritative double-entry record, the aggregate just guards its own balances.

### D3: Explicit lifecycle state machine
`AccountStatus` is `Open | Frozen | Closed`. Every mutating operation first asserts the
account is `Open`; otherwise it raises `AccountNotActive`. `freeze` and `close` are the only
transitions. This makes "no operations on a frozen/closed account" a single guard.

- *Open questions handled:* freezing a non-open account and closing a non-open account are
  rejected (no silent re-transition); closing is allowed regardless of balance for now
  (whether to require zero balance is deferred — the transfer/ledger work will clarify).

### D4: Invariants enforced before recording events
Each operation validates (status, amount positivity, currency match, sufficient
available/reserved) and only then calls `recordThat(...)`. Because events are applied to
mutate state, an invalid command throws before any event is recorded — the aggregate never
emits an event that would violate an invariant. State changes live only in the `apply()`
handlers (replay-safe; no validation on replay).

### D5: Event-sourced repository, stream type `account`
`AccountRepository` (port) has `load(AccountId): Account` and `save(Account, ?EventMetadata)`.
The event-sourced adapter maps `AccountId` to `StreamId::of('account', id)`, loads via
`EventStore::load` + `Account::reconstituteFromHistory`, and on save appends the pulled
uncommitted events with the aggregate's prior version as the expected version (optimistic
concurrency). Account event types are registered on the shared `EventTypeRegistry` so they
serialize.

- *Note:* correlation/causation `EventMetadata` is threaded through `save()` but populated by
  the command layer later; for now it defaults to none.

### D6: Stable event type names + reserved schema version
Event type strings are namespaced (e.g. `accounts.account_opened`) and registered with
`schemaVersion = 1`. The field is persisted now so upcasters can be added later without a
data migration (the example `AccountOpened` v1→v2 upcaster is deferred to the
event-versioning work).

## Risks / Trade-offs

- **In-house `Money` could miss edge cases (allocation, rounding) a library handles** →
  Mitigation: scope is single-currency integer arithmetic with no division at this layer;
  unit-tested; swappable behind the VO if needs grow.
- **Closing an account with a non-zero balance may be undesirable** → Mitigation: documented
  as deferred; easy to add a guard once transfer/settlement semantics are fixed.
- **Replay must stay side-effect-free** → Mitigation: `apply()` handlers only mutate fields;
  all validation lives in the command methods, not in `apply()`.

## Open Questions

- Should `close` require a zero total (no residual available/reserved)? Deferred until the
  transfer saga defines settlement.
- Where account event-type registration ultimately lives (per-context registrar vs. central
  config) — start with DI configuration; revisit if it becomes unwieldy across contexts.
