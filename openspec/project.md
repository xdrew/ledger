# Project: ledger-core

An event-sourced payment ledger — the backend internals of a wallet / neobank-style
service. Accounts hold money; users deposit, transfer, and reverse. The entire point
is **correctness of money under concurrency**: no lost updates, no double-spend, full
auditability. This is a senior/principal-grade backend portfolio piece demonstrating
DDD, CQRS, Event Sourcing, sagas, idempotency under concurrency, and production-grade
ops wrapping — without any UI.

## Vision & Scope

**In scope:**
- Account lifecycle (open, deposit, freeze, close; available vs reserved balance)
- Double-entry ledger (immutable, balanced postings)
- Transfers as a saga (hold → post → complete / compensate; reversal)
- Idempotency of mutating requests
- Read projections (balances, statement; rebuildable)
- Transactional outbox for reliable event publication
- HTTP API (OpenAPI 3.1, JSON error envelope; travel-project conventions)
- Full ops wrapping: observability, deployment, CI

**Explicitly out of scope (non-goals):**
- UI / frontend of any kind
- Real banking rails / card networks
- KYC / compliance flows
- Multi-tenant auth beyond a simple API key
- FX / cross-currency conversion — accounts are single-currency; cross-currency is a
  non-goal. The ledger enforces currency consistency per entry.

## Tech Stack & Constraints

- **PHP 8.5+**, **Symfony 8** (kernel, DI, console; messenger optional)
- **RoadRunner** as app server and worker host (HTTP plugin + jobs/queue plugin +
  metrics plugin for Prometheus)
- **PostgreSQL 18** as event store, outbox, and projection store
- **Message bus:** [`thesis/message-bus`](https://github.com/thesis-php/message-bus) as
  the in-process command / query / event bus (CQRS dispatch + middleware pipeline).
  This is the application-layer bus; it is distinct from the transactional outbox, which
  handles reliable *cross-process* event publication.
- **Async PHP (where warranted):** prefer fiber-based, non-blocking I/O for I/O-bound,
  long-running workers — the outbox relay, projectors, and transport subscribers — using
  the fiber-based [`thesis-php`](https://github.com/thesis-php) ecosystem
  (`thesis/nats`, `thesis/amqp`, `thesis/pgmq`) where it offers a genuine concurrency
  win. RoadRunner already hosts these as long-running worker processes. Async is adopted
  **only where there are real prerequisites** (concurrent I/O fan-out, many open
  subscriptions, blocking-wait latency) — never gratuitously, and never in the domain
  layer, which stays synchronous, deterministic, and side-effect-free. This mirrors the
  project's "deliberate restraint" ethos: power where it pays for itself, plain code
  everywhere else.
- **Write side:** no ORM for aggregates — a hand-rolled event store on Doctrine DBAL
  (raw SQL). Read side may use DBAL / simple repositories. Integration via the Doctrine
  Symfony bundles (`doctrine-bundle` ^3, `doctrine-migrations-bundle` ^4, which support
  Symfony 8) for the DBAL connection and migrations; `doctrine/orm` is intentionally not
  installed.
- **Money** as integer minor units + currency code. Never floats. Always via a `Money`
  value object.
- Single-currency accounts; ledger enforces currency consistency per entry.
- All PHP: `declare(strict_types=1)`, PSR-12, PHPStan level max.

## Architecture Principles

- **Hexagonal / ports & adapters.** The domain has zero framework dependencies.
- **Bounded contexts as separate modules:** `Accounts`, `Ledger`, `Transfers`. A
  shared kernel holds only `Money`, identifiers, and event base types.
- **CQRS:** commands mutate via aggregates → events; queries read from projections only.
  Commands, queries, and events are dispatched through `thesis/message-bus`, with
  cross-cutting concerns (validation, transactions, correlation propagation) as bus
  middleware.
- **Event Sourcing on the write side** for `Accounts`, `Ledger`, `Transfers`. The event
  store is append-only; aggregates are rehydrated from their stream; **optimistic
  concurrency** is enforced via expected stream version.
- **Deliberate restraint:** the idempotency store and the read projections are **plain
  mutable tables, not event-sourced.** This is intentional and documented in an ADR.
  Event Sourcing is used only where audit/history is a real domain requirement, nowhere
  else — over-applying ES is a cost, not a virtue.
- **Transactional outbox** for reliable event publication: domain events are written in
  the same DB transaction as the aggregate append, then relayed asynchronously.

## Cross-Cutting Requirements

- **Concurrency:** every aggregate write uses expected-version optimistic locking;
  conflicts surface as a retriable `409`.
- **Event versioning:** events carry a schema version; an upcaster mechanism migrates
  old event shapes on load. Documented in an ADR.
- **Correlation:** every command carries a correlation/causation id, propagated into
  events, logs, and traces.
- **Time:** a `Clock` is injected; no `new DateTime()` in the domain.
- **Determinism:** projectors and sagas are replay-safe and side-effect-free except
  through ports.

## Project Conventions

- Work proceeds strictly through OpenSpec: one capability per change = one reviewable
  PR. Never bundle capabilities.
- For every change: `proposal.md`, `design.md` (where a decision is non-trivial),
  `tasks.md`, and a spec delta under `changes/<id>/specs/<capability>/spec.md`.
- `openspec validate <id> --strict` is a hard gate and must pass before any
  implementation code is written.
- Implement → tests green → `openspec archive <id>`.

## Capability Map

| Capability      | Responsibility                                                       |
|-----------------|---------------------------------------------------------------------|
| `accounts`      | Account lifecycle aggregate; available vs reserved balance          |
| `ledger`        | Immutable double-entry journal; balanced postings                   |
| `transfers`     | Transfer saga: hold → post → complete / compensate; reversal        |
| `idempotency`   | Dedup of mutating requests via Idempotency-Key                      |
| `projections`   | Read models (balances, statement); rebuildable                      |
| `outbox`        | Transactional outbox + relay worker                                 |
| `api`           | HTTP surface, OpenAPI 3.1, JSON error envelope (travel conventions) |
| `observability` | Health, metrics, traces, structured logs, dashboards               |
| `deployment`    | Docker, docker compose, Kubernetes/Helm                             |
| `ci`            | Pipeline: lint, static analysis, tests, spec-validate gate, image  |
