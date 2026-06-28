> Depends on the archived `event-store` capability (`AggregateRoot`, `EventStore`,
> serializer registry). No new third-party dependencies; no new tables.

## 1. Shared-kernel money

- [ ] 1.1 Implement `App\SharedKernel\Money\Currency` (validated ISO-4217-style 3-letter code, equality).
- [ ] 1.2 Implement `App\SharedKernel\Money\Money` (int minor units + `Currency`): `add`, `subtract`, `isNegative`, `isZero`, comparisons, `equals`, `zero(currency)`; reject cross-currency operations with `CurrencyMismatch`; no floats.
- [ ] 1.3 Unit-test `Money`/`Currency`: arithmetic, comparisons, zero, and currency-mismatch rejection.

## 2. Accounts domain

- [ ] 2.1 Implement `AccountId` and `AccountStatus` (Open | Frozen | Closed).
- [ ] 2.2 Implement the eight events under `App\Accounts\Domain\Event` (`AccountOpened`, `FundsDeposited`, `FundsHeld`, `HoldReleased`, `FundsDebited`, `FundsCredited`, `AccountFrozen`, `AccountClosed`), each a `DomainEvent` with `toPayload`/`fromPayload`.
- [ ] 2.3 Implement domain exceptions under `App\Accounts\Domain\Exception` (`AccountNotActive`, `InsufficientFunds`, `InvalidAmount`, plus reuse of `CurrencyMismatch`).
- [ ] 2.4 Implement the `Account` aggregate on `AggregateRoot`: `open`, `deposit`, `hold`, `releaseHold`, `debit`, `credit`, `freeze`, `close`; validate (status, positivity, currency, sufficiency) before `recordThat`; mutate state only in `apply()`.

## 3. Persistence

- [ ] 3.1 Define the `AccountRepository` port (`load(AccountId): Account`, `save(Account, ?EventMetadata): void`).
- [ ] 3.2 Implement `EventSourcedAccountRepository` over `EventStore` (stream type `account`): load + `reconstituteFromHistory`; save by appending pulled uncommitted events at the expected prior version.
- [ ] 3.3 Register the account event types on the shared `EventTypeRegistry` and bind the `AccountRepository` port to the adapter (DI).

## 4. Tests

- [ ] 4.1 Aggregate unit tests covering every invariant: open; deposit; hold (incl. insufficient funds); release (incl. over-release); debit (incl. over-debit); credit; freeze/close; no-ops on frozen/closed; positive-amount enforcement; currency consistency; `available ≥ 0`; `0 ≤ reserved ≤ total`.
- [ ] 4.2 Integration test: open → deposit → hold, save via `EventSourcedAccountRepository`, reload, and assert identical status/currency/available/reserved (rehydration round-trip through Postgres).

## 5. Verification & gate

- [ ] 5.1 Confirm the "done" criterion: aggregate unit tests cover every invariant and pass.
- [ ] 5.2 Green: php-cs-fixer (phpyh ruleset), PHPStan max, unit + integration suites; `openspec validate add-accounts-capability --strict` passes before implementation review/archive.
