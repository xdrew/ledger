> Depends on the archived `event-store` capability (`AggregateRoot`, `EventStore`,
> serializer registry). No new third-party dependencies; no new tables.

## 1. Shared-kernel money

- [x] 1.1 Implement `App\SharedKernel\Money\Currency` (validated ISO-4217-style 3-letter code, equality).
- [x] 1.2 Implement `App\SharedKernel\Money\Money` (int minor units + `Currency`): `add`, `subtract`, `isNegative`, `isZero`, `isPositive`, comparisons, `equals`, `zero(currency)`; reject cross-currency operations with `CurrencyMismatch`; no floats.
- [x] 1.3 Unit-test `Money`/`Currency`: arithmetic, comparisons, zero, and currency-mismatch rejection.

## 2. Accounts domain

- [x] 2.1 Implement `AccountId` and `AccountStatus` (Open | Frozen | Closed).
- [x] 2.2 Implement the eight events under `App\Accounts\Domain\Event` (`AccountOpened`, `FundsDeposited`, `FundsHeld`, `HoldReleased`, `FundsDebited`, `FundsCredited`, `AccountFrozen`, `AccountClosed`), each a `DomainEvent` with `toPayload`/`fromPayload` (shared `AbstractMoneyEvent`/`AbstractAccountEvent` bases).
- [x] 2.3 Implement domain exceptions (`AccountNotActive`, `InsufficientFunds`, `InvalidAmount`, `AccountNotFound`), reusing `CurrencyMismatch`.
- [x] 2.4 Implement the `Account` aggregate on `AggregateRoot`: `open`, `deposit`, `hold`, `releaseHold`, `debit`, `credit`, `freeze`, `close`; validate (status, positivity, currency, sufficiency) before `recordThat`; mutate state only in `apply()`.

## 3. Persistence

- [x] 3.1 Define the `AccountRepository` port (`load(AccountId): Account`, `save(Account, ?EventMetadata): void`).
- [x] 3.2 Implement `EventSourcedAccountRepository` over `EventStore` (stream type `account`): load + `reconstituteFromHistory`; save by appending pulled uncommitted events at the expected prior version.
- [x] 3.3 Register the account event types on the shared `EventTypeRegistry` via the `AccountEventTypes` registrar (DI configurator, also used by tests to avoid drift) and bind the `AccountRepository` port to the adapter.

## 4. Tests

- [x] 4.1 Aggregate unit tests covering every invariant: open; deposit; hold (incl. insufficient funds, balances untouched on rejection); release (incl. over-release); debit (incl. over-debit); credit; freeze/close; no-ops on frozen/closed; positive-amount enforcement; currency consistency; `available ≥ 0`; `0 ≤ reserved ≤ total`; version/event recording.
- [x] 4.2 Integration test: open → deposit → hold, save via `EventSourcedAccountRepository`, reload, and assert identical status/currency/available/reserved; plus a subsequent-append round-trip (rehydration through Postgres).

## 5. Verification & gate

- [x] 5.1 "Done" criterion confirmed: aggregate unit tests cover every invariant and pass.
- [x] 5.2 Green: php-cs-fixer (phpyh ruleset, 0 issues), PHPStan max (no errors), unit (46) + integration (10) suites; `openspec validate add-accounts-capability --strict` passes.
