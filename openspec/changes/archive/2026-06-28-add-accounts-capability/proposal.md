## Why

The ledger needs accounts before anything can hold money. The `Account` is the first
event-sourced aggregate and the place where the core money-correctness invariants live —
balances never go negative, reserved funds never exceed the total, and a frozen or closed
account rejects all mutations. Everything downstream (ledger postings, the transfer saga)
depends on these guarantees.

## What Changes

- Introduce a shared-kernel **`Money`** value object (integer minor units + **`Currency`**;
  never floats) and an **`AccountId`** identifier.
- Introduce the **`Account`** aggregate (on the event-store foundation's `AggregateRoot`)
  with operations: open, deposit, place hold, release hold, debit, credit, freeze, close.
- Emit the domain events: `AccountOpened`, `FundsDeposited`, `FundsHeld`, `HoldReleased`,
  `FundsDebited`, `FundsCredited`, `AccountFrozen`, `AccountClosed`.
- Enforce invariants in the aggregate: operations only on an active (open) account;
  available balance never negative; reserved balance never negative and never exceeds the
  total; amounts strictly positive; currency consistent with the account.
- Provide an **`AccountRepository`** port and an event-sourced implementation backed by the
  event store (stream type `account`), and register the account event types in the
  serializer registry so accounts round-trip through Postgres.

Out of scope (later changes): command handlers / message-bus dispatch and HTTP endpoints
(`add-http-api`), idempotency (`add-idempotency`), the double-entry journal
(`add-ledger-capability`), and event upcasting (the `schema_version` field already exists;
an upcaster mechanism + example land with the event-versioning work).

## Capabilities

### New Capabilities
- `accounts`: the account lifecycle aggregate — open/deposit/hold/release/debit/credit/
  freeze/close — with available vs reserved balances and the money-safety invariants,
  persisted and rehydrated via the event store.

### Modified Capabilities
<!-- None. Builds on the archived `event-store` capability without changing it. -->

## Impact

- **New code:** `App\SharedKernel\Money\{Money, Currency}`; `App\Accounts\Domain\`
  (`Account`, `AccountId`, `AccountStatus`, `Event\*`, `Exception\*`, `AccountRepository`);
  `App\Accounts\Infrastructure\EventSourcedAccountRepository`.
- **Wiring:** register account event types on the shared `EventTypeRegistry`; bind the
  `AccountRepository` port to its event-sourced adapter.
- **Depends on** the `event-store` capability (`AggregateRoot`, `EventStore`,
  serialization). No new third-party dependencies.
- **Database:** no new tables — accounts live as streams in the existing `events` table.
