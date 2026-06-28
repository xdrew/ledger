## Context

CQRS dispatch belongs behind a bus so the entry points (HTTP now, workers later) don't call
application services directly and so cross-cutting concerns (correlation, transactions) live in
one pipeline. The aggregates, `TransferOrchestrator`, repositories, and projection views already
exist; this change adds the bus, the messages, the handlers, and correlation propagation.

Constraints from `project.md`: CQRS via `thesis/message-bus`; correlation/causation ids
propagated into events (§6); the domain stays framework-free (handlers live in each context's
`Application` layer and depend only on ports).

## Goals / Non-Goals

**Goals:**
- A command bus and a query bus over `thesis/message-bus`, with handler registration + middleware.
- Account/transfer command handlers and read-model query handlers.
- Correlation id carried with a message and written into `EventMetadata` on saves.
- Fully testable by dispatching messages — no HTTP.

**Non-Goals (later):** HTTP controllers, auth, idempotency, OpenAPI (`add-http-api`); async
message transport (the outbox already covers cross-process publication); sagas-as-bus-subscribers
(the transfer orchestrator stays a synchronous service for now).

## Decisions

### D1: Two buses — command (void, one handler) and query (returns, one handler)
A command has exactly one handler and returns nothing meaningful; a query has exactly one handler
and returns a read result. Two buses make the read/write split explicit and keep middleware
concerns separate. Handlers are registered by message type (attribute or tag) per the library's
convention, pinned at implementation against `thesis/message-bus`.

- *Alternatives rejected:* a single bus for both (blurs CQRS, complicates middleware); Symfony
  Messenger (the project standardized on `thesis/message-bus`).

### D2: Correlation via a message envelope + middleware
Each dispatched message carries (or is wrapped with) a correlation id and an optional causation
id. A middleware establishes the current correlation context; command handlers read it and pass
an `EventMetadata` into `repository->save(...)`/the orchestrator, so the resulting events record
the id. If no id is supplied, one is generated at the edge.

- *Alternatives rejected:* a global mutable "current request id" singleton (hidden state, hard to
  test); threading the id through every method signature (noise) — the envelope + middleware keeps
  it explicit but unobtrusive.

### D3: Handlers are thin application services
`OpenAccount` → `Account::open` + `accounts->save`; `DepositFunds` → load, `deposit`, save;
`InitiateTransfer` → `TransferOrchestrator::initiate`. Queries: `GetAccountBalance` /
`GetAccountStatement` → projection views; `GetTransfer` → transfer repository → a read DTO. No
business logic leaks into handlers — they orchestrate ports.

### D4: Idempotency stays at the HTTP edge, not in the bus
Request deduplication is an HTTP concern (the `Idempotency-Key` header) handled in `add-http-api`.
The bus does not own idempotency; keeping it at the edge avoids conflating transport-level retries
with domain dispatch.

## Risks / Trade-offs

- **`thesis/message-bus` API is sparsely documented** → Mitigation: pin the handler/middleware
  conventions against the library source at implementation; the design is library-agnostic.
- **Correlation threading adds a parameter to saves** → Mitigation: `EventMetadata` already exists
  and is optional on `save()`; handlers pass it, callers without a context pass none.

## Open Questions

- Whether queries return domain/read DTOs or arrays — default to the existing read DTOs
  (`AccountBalance`, `StatementEntry`) and a small transfer read DTO.
- Causation id semantics (message-to-event vs event-to-event) — start with correlation only;
  add causation when the async choreography needs it.
