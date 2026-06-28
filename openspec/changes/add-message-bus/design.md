## Context

CQRS write dispatch belongs behind a bus so entry points don't call application services
directly and so correlation (and later async transport) live in one pipeline. The aggregates,
`TransferOrchestrator`, and repositories already exist; this change adds the command bus, the
handlers, and correlation propagation. Reads are not a bus concern — they hit the projection
read views directly.

Key constraint discovered: `thesis/message-bus` is an **async** bus (Pgmq transport, consumer
runtimes, replies-as-messages); its dispatch is fire-and-forget (`void`) with no synchronous
request/reply. That is fine here because **only commands use the bus, and commands need no return
value** — so we use the library's core `Handlers`/`Context` synchronously in-process.

## Goals / Non-Goals

**Goals:**
- A synchronous in-process `CommandBus` over `thesis/message-bus` core.
- Account/transfer command handlers.
- Correlation id carried in message `Metadata` and written into `EventMetadata` on save.
- Fully testable by dispatching commands — no HTTP.

**Non-Goals (later):** the async Pgmq/NATS transport + a command worker (a transport swap, no
handler change); HTTP/controllers; query handlers (queries read views directly — not a bus concern).

## Decisions

### D1: Synchronous `CommandBus` over `thesis/message-bus` core (`Handlers` + `Context`)
`CommandBus::dispatch(object $command, ?string $correlationId = null): void` builds a `Metadata`
(message id, `conversationId` = correlation, `kind = Command`, origin, createdAt), wraps it in a
`Context` (with a no-op transaction), and calls the library's `Handlers::handle($command,
$context)`. We deliberately use only the core handler-routing + context, not the async machinery
(no `Dispatcher`/`ConsumerRuntime`/Pgmq), so commands run in-process and synchronously.

- *Alternatives rejected:* the full async `MessageBus` (Pgmq + consumer runtime — not needed for
  in-process commands, and offers no synchronous result); an in-house bus or telephantast (the
  reviewer chose to use thesis); a sync *transport* (unnecessary — only commands use the bus and
  they return nothing).

### D2: Commands return nothing; ids are pre-generated; outcomes are read back
Handlers are `void`. The caller generates the aggregate id (`AccountId::generate()` /
`TransferId::generate()`), puts it in the command, dispatches, and — if it needs the result —
reads it from the projection/repository afterwards. This sidesteps the absence of request/reply
and matches eventual-consistency reads (ADR-003).

### D3: Queries bypass the bus
Read endpoints call the existing `AccountBalanceView` / `AccountStatementView` and the transfer
repository directly. There is no query bus — queries returning values were the only thing that
wanted synchronous request/reply, and reading projections directly is standard CQRS.

### D4: Correlation via message `Metadata` → `EventMetadata`
`Metadata.conversationId` is the correlation id (`Metadata.id` the message id, `causeId` the
causation). Account command handlers read these from the `Context` and pass
`new EventMetadata(correlationId: conversationId, causationId: messageId)` into
`repository->save(...)`, so the recorded events carry the correlation id. (Threading correlation
through the transfer saga's many saves is a follow-up; account commands establish the pattern.)

### D5: Thin handlers
`OpenAccount` → `Account::open` + save; `DepositFunds` → load, `deposit`, save; `InitiateTransfer`
→ `TransferOrchestrator::initiate`. No business logic in handlers — they orchestrate ports.

## Risks / Trade-offs

- **`thesis/message-bus` is 0.5-dev with a churny async-first API** → Mitigation: we depend only
  on its stable-ish core (`Handlers`, `Context`, `Metadata`); the surface we use is tiny and
  wrapped behind our `CommandBus`, so a future API shift touches one class.
- **Using an async bus synchronously may look unusual** → Mitigation: documented (D1); it is the
  same library the project will use for async publication later, kept consistent.
- **Correlation not yet threaded through the transfer saga** → Mitigation: account commands prove
  the mechanism; saga threading is a small follow-up.

## Open Questions

- Handler registration: explicit constructor wiring of the (currently three) handlers vs. a
  tagged compiler pass — start explicit; move to a tag when handlers proliferate.
- When the async transport lands, which commands (if any) become truly async — decided with the
  worker/deployment work.
