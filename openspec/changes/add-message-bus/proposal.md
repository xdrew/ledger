## Why

The HTTP API (and later workers) must drive the domain through CQRS — commands mutate via
aggregates, queries read from projections — dispatched over a message bus, not by calling
services ad hoc. Building the bus and the command/query handlers as their own change keeps the
HTTP change thin and lets the dispatch layer be tested without any HTTP. It is also where
correlation ids start flowing from a request into the events it produces.

## What Changes

- Integrate **`thesis/message-bus`** as the in-process **command bus** and **query bus**, with a
  handler-registration convention and a **middleware** pipeline.
- Add a **correlation middleware** that carries a correlation/causation id with each message and
  makes it available to handlers, which thread it into `EventMetadata` on aggregate saves
  (so events, and later logs/traces, share the id).
- Add **commands + handlers**: `OpenAccount`, `DepositFunds` (accounts) and `InitiateTransfer`
  (delegates to the `TransferOrchestrator`).
- Add **queries + handlers**: `GetAccountBalance`, `GetAccountStatement` (projection read models)
  and `GetTransfer` (transfer repository).
- Wire it all in DI so a controller (next change) just builds a message and dispatches it.

## Capabilities

### New Capabilities
- `messaging`: in-process command/query buses (`thesis/message-bus`) with middleware, the
  account/transfer command handlers and the read-model query handlers, and correlation-id
  propagation into event metadata.

### Modified Capabilities
<!-- None at the spec level; handlers compose existing aggregates, orchestrator, and projections. -->

## Impact

- **New code:** bus configuration + middleware; `App\Accounts\Application` and
  `App\Transfers\Application` command/query messages + handlers; a message context carrying the
  correlation id.
- **Dependencies (new):** `thesis/message-bus`.
- **Depends on** accounts, transfers, projections, idempotency-free (idempotency stays at the HTTP
  edge). No HTTP, no new tables.
- **Downstream:** `add-http-api` dispatches these commands/queries from controllers.
