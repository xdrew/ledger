> Depends on accounts, transfers (orchestrator). New dep: thesis/message-bus (used in-process
> for commands). No HTTP, no new tables. Queries are direct (not part of this change).

## 1. Command bus

- [x] 1.1 Add `thesis/message-bus` (`^0.5@dev`).
- [x] 1.2 Implement `App\Messaging\CommandBus` over the library's `Handlers` + `Context`: `dispatch(command, ?correlationId)` builds `Metadata` (id, conversationId=correlation, Kind::Command) + a no-op transaction and calls `Handlers::handle` synchronously.
- [x] 1.3 Wire DI (CommandBus + handlers autowired; command DTOs excluded from the service resource).

## 2. Commands & handlers

- [x] 2.1 `OpenAccount` (AccountId, Currency) + handler → `Account::open` + save with `EventMetadata` from the correlation context.
- [x] 2.2 `DepositFunds` (AccountId, Money) + handler → load, `deposit`, save (with correlation metadata).
- [x] 2.3 `InitiateTransferHandler` for the existing `InitiateTransfer` input → `TransferOrchestrator::initiate`.

## 3. Tests

- [x] 3.1 Command reaches its handler; an unregistered command raises a no-handler error.
- [x] 3.2 `OpenAccount` + `DepositFunds` via the bus create the account and update its balance.
- [x] 3.3 `InitiateTransfer` via the bus completes the transfer through the orchestrator.
- [x] 3.4 Correlation: `OpenAccount` dispatched with a correlation id yields account events whose metadata carry that id.

## 4. Verification & gate

- [x] 4.1 Confirm synchronous command dispatch works (command → effect) with correlation propagation, no HTTP.
- [x] 4.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration suites; `openspec validate add-message-bus --strict` passes.
