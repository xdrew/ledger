## Why

The HTTP API needs to drive writes through CQRS — commands mutate via aggregates → events —
behind a bus, so entry points don't call application services ad hoc and so cross-cutting
concerns (correlation, later async transport) live in one place. Building the command bus and
its handlers as their own change keeps the HTTP change thin and lets dispatch be tested with no
HTTP. It is also where correlation ids start flowing from a request into the events it produces.

Reads do **not** go through a bus: queries read the projection read models directly (standard
CQRS). That removes any need for synchronous request/reply, so `thesis/message-bus` fits — used
**in-process and synchronously** for commands now, swappable to its Pgmq/NATS transport for true
async later with no handler changes.

## What Changes

- Add **`thesis/message-bus`** and a thin synchronous **`CommandBus`** over its core
  `Handlers` + `Context` (no Pgmq, no consumer runtime, no request/reply): `dispatch(command)`
  runs the registered handler in-process.
- Add **command handlers**: `OpenAccount`, `DepositFunds` (accounts) and reuse the existing
  `InitiateTransfer` input as the transfer command (handler delegates to `TransferOrchestrator`).
- **Correlation**: each dispatch carries a conversation (correlation) id in the message
  `Metadata`; account handlers read it from the `Context` and thread it into `EventMetadata` on
  save, so the recorded events carry the correlation id.
- Commands carry no return value; the caller pre-generates ids and (if it needs the outcome)
  reads it back from projections/repositories afterwards.

Queries are explicitly **out of the bus**: controllers (next change) call the existing read
views (`AccountBalanceView`, `AccountStatementView`) and the transfer repository directly.

## Capabilities

### New Capabilities
- `messaging`: an in-process synchronous command bus (`thesis/message-bus`) with the account and
  transfer command handlers and correlation-id propagation into event metadata.

### Modified Capabilities
<!-- None at the spec level; handlers compose existing aggregates and the orchestrator. -->

## Impact

- **New code:** `App\Messaging\CommandBus` (+ a no-op transaction marker); `App\Accounts\Application`
  `OpenAccount`/`DepositFunds` commands + handlers; `App\Transfers\Application\InitiateTransferHandler`.
- **Dependencies (new):** `thesis/message-bus` (`^0.5@dev` — the thesis-php async bus, used here
  in-process for commands; pinned dev as it has no stable release yet).
- **Depends on** accounts, transfers (orchestrator). No HTTP, no new tables. Queries stay direct.
- **Downstream:** `add-http-api` controllers dispatch these commands and read projections directly.
