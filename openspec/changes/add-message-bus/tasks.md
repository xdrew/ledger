> Depends on accounts, transfers, projections. New dep: thesis/message-bus. No HTTP, no new tables.

## 1. Bus integration

- [ ] 1.1 Add `thesis/message-bus`; configure a command bus and a query bus with handler registration (attribute/tag convention pinned against the library).
- [ ] 1.2 Implement a message context carrying a correlation id and a correlation middleware that establishes it per dispatch (generating one if absent).
- [ ] 1.3 Wire DI: the two buses, middleware, and handler registration.

## 2. Commands & handlers

- [ ] 2.1 `OpenAccount` (accountId, currency) → `Account::open` + save (with `EventMetadata` from the correlation context).
- [ ] 2.2 `DepositFunds` (accountId, Money) → load, `deposit`, save.
- [ ] 2.3 `InitiateTransfer` (ids, Money) → `TransferOrchestrator::initiate`.

## 3. Queries & handlers

- [ ] 3.1 `GetAccountBalance` (accountId) → `AccountBalanceView`.
- [ ] 3.2 `GetAccountStatement` (accountId) → `AccountStatementView`.
- [ ] 3.3 `GetTransfer` (transferId) → transfer repository → a transfer read DTO.

## 4. Tests

- [ ] 4.1 Command bus: a command reaches its single handler; an unregistered command errors.
- [ ] 4.2 Query bus: a query returns its handler's result.
- [ ] 4.3 Handlers: open+deposit via commands updates state; initiate transfer completes; queries return read data.
- [ ] 4.4 Correlation: a command dispatched with a correlation id yields events whose metadata carry that id.

## 5. Verification & gate

- [ ] 5.1 Confirm CQRS dispatch works end-to-end (command → effect, query → result) without HTTP.
- [ ] 5.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration suites; `openspec validate add-message-bus --strict` passes.
