# Project Brief — `ledger-core`: Event-Sourced Payment Ledger

**Audience:** Claude Code, driving implementation through **OpenSpec**.
**Goal of the artifact:** a senior/principal-grade backend portfolio piece that demonstrates DDD, CQRS, Event Sourcing, sagas, idempotency under concurrency, and production-grade ops wrapping — without UI.

---

## 0. How to use this brief (workflow)

1. Run `openspec init` (if not initialized). Populate `openspec/project.md` from §1–§3 below.
2. Implement the project as a **sequence of OpenSpec changes** (§5). **One capability per change = one reviewable PR.** Do not bundle.
3. For every change: write `proposal.md` (Why / What Changes / Impact), `design.md` where a decision is non-trivial, `tasks.md` (checklist), and the spec delta under `changes/<id>/specs/<capability>/spec.md`.
4. Run `openspec validate <id> --strict` **before** writing any implementation code. Fix until it passes.
5. Implement → tests green → `openspec archive <id>`.
6. Defer to `openspec/AGENTS.md` for exact spec/delta file format (requirement + scenario, `## ADDED/MODIFIED/REMOVED Requirements`, `SHALL` language, `WHEN/THEN` scenarios). The requirements in this brief are written in plain prose — translate them into OpenSpec's format, do not paste verbatim.

Spec format reminder (illustrative — follow the tool's real convention):

```
### Requirement: Source account funds SHALL be reserved before posting
The transfer SHALL place a hold on the source account equal to the
transfer amount before any ledger posting occurs.

#### Scenario: Insufficient available balance
- WHEN a transfer is initiated for an amount exceeding available balance
- THEN the transfer fails with `INSUFFICIENT_FUNDS` and no hold is created
```

---

## 1. Vision & scope

A backend payment core — the internals of a wallet / neobank-style service. Accounts hold money; users deposit, transfer, and reverse. The entire point is **correctness of money under concurrency**: no lost updates, no double-spend, full auditability.

**In scope:** account lifecycle, double-entry ledger, transfers as a saga, idempotency, read projections, transactional outbox, HTTP API, full ops wrapping.

**Explicitly out of scope (state this in project.md):** UI/frontend, real banking rails / card networks, KYC, multi-tenant auth beyond a simple API key, FX conversion (single-currency per account is fine; cross-currency is a non-goal).

---

## 2. Tech stack & constraints

- **PHP 8.3+**, **Symfony 7** (kernel, DI, console, messenger optional).
- **RoadRunner** as the app server and worker host (HTTP plugin + jobs/queue plugin + **metrics plugin** for Prometheus).
- **PostgreSQL 16** as event store, outbox, and projection store.
- Write side: **no ORM for aggregates** — hand-rolled event store on Doctrine DBAL (raw SQL). Read side may use DBAL/simple repositories.
- Money as integer minor units + currency code. Never floats. Use a `Money` value object.
- Single-currency accounts; ledger enforces currency consistency per entry.
- PHP code: `declare(strict_types=1)`, PSR-12, PHPStan level max.

---

## 3. Architecture principles

- **Hexagonal / ports & adapters.** Domain has zero framework dependencies.
- **Bounded contexts** as separate modules: `Accounts`, `Ledger`, `Transfers`. Shared kernel only for `Money`, identifiers, and event base types.
- **CQRS:** commands mutate via aggregates → events; queries read from projections only.
- **Event Sourcing on the write side** for `Accounts`, `Ledger`, `Transfers`. Append-only event store; aggregates rehydrated from their stream; **optimistic concurrency** via expected stream version.
- **Deliberate restraint (read §8):** the idempotency store and the read projections are **plain mutable tables, not event-sourced.** This is intentional and must be documented in an ADR. ES is used where audit/history is a real domain requirement, nowhere else.
- **Transactional outbox** for reliable event publication.

---

## 4. Capability map

These become the `openspec/specs/<capability>/spec.md` files:

| Capability | Responsibility |
|---|---|
| `accounts` | Account lifecycle aggregate; available vs reserved balance |
| `ledger` | Immutable double-entry journal; balanced postings |
| `transfers` | Transfer saga: hold → post → complete / compensate; reversal |
| `idempotency` | Dedup of mutating requests via Idempotency-Key |
| `projections` | Read models (balances, statement); rebuildable |
| `outbox` | Transactional outbox + relay worker |
| `api` | HTTP surface, OpenAPI 3.1, RFC 9457 errors |
| `observability` | Health, metrics, traces, structured logs, dashboards |
| `deployment` | Docker, docker compose, Kubernetes/Helm |
| `ci` | Pipeline: lint, static analysis, tests, spec-validate gate, image build |

---

## 5. Recommended change sequence

Each item is one OpenSpec change. Keep this order — later changes depend on earlier ones.

**`add-event-store-foundation`** — Append-only event store on Postgres (streams, version, global ordering), event (de)serialization, optimistic concurrency, an in-memory test double. Plus base aggregate root. *Done:* can append/load a stream; concurrent append with stale version is rejected.

**`add-accounts-capability`** — `Account` aggregate. Commands: `OpenAccount`, `Deposit`, `FreezeAccount`, `CloseAccount`. Events: `AccountOpened`, `FundsDeposited`, `FundsHeld`, `HoldReleased`, `FundsDebited`, `FundsCredited`, `AccountFrozen`, `AccountClosed`. Invariants: no operations on frozen/closed account; available balance never negative; reserved balance ≤ total. *Done:* aggregate unit tests cover every invariant.

**`add-ledger-capability`** — Immutable double-entry journal. Command: `PostJournalEntry` with ≥2 legs. Event: `JournalEntryPosted`. Invariants: legs sum to zero per currency; no posting referencing a closed account; entries never mutate. A **trial-balance invariant test**: across all entries, every account's debits−credits reconciles and the system nets to zero. *Done:* property-style test posts N random balanced entries and asserts global zero-sum.

**`add-idempotency`** — `Idempotency-Key` header on all mutating endpoints. Plain table keyed by (key, route, request hash) → stored response. Replay of completed key returns stored response; in-flight key returns `409 Conflict`; mismatched payload for same key returns `422`. Configurable TTL. *Done:* concurrent duplicate POSTs produce exactly one state change.

**`add-transfers-saga`** — `Transfer` process manager orchestrating: `Initiate` → place hold on source (`FundsHeld`) → post double-entry (debit source / credit destination) → `TransferCompleted`; on any failure → release hold (`HoldReleased`) → `TransferFailed`. States: `Initiated, Held, Posted, Completed, Failed`. Insufficient available funds → fail at hold step, no partial effects. `ReverseTransfer` of a completed transfer creates a new compensating transfer (never edits history). *Done:* saga unit tests for happy path, insufficient funds, failure-after-hold compensation, reversal.

**`add-projections`** — Async projectors building read models: `account_balances` (account_id, currency, available, reserved, total, version) and `account_statement` (per-account ordered postings/holds). Must be **fully rebuildable** from the event store via a console command (`projections:rebuild`). Expose **projection lag** as a metric. *Done:* drop+rebuild yields identical balances to live projection.

**`add-outbox`** — Transactional outbox: domain events written in the **same DB transaction** as the aggregate append. A relay worker (RoadRunner job) reads unpublished rows in order and publishes to an internal bus (Postgres `LISTEN/NOTIFY` is acceptable for the demo; abstract the transport behind a port so NATS JetStream can be swapped in). At-least-once delivery; consumers (projectors) are idempotent by event id. *Done:* killing the relay mid-batch and restarting loses no events and double-delivers none observably.

**`add-http-api`** — OpenAPI 3.1 spec checked into repo and served. Endpoints: `POST /accounts`, `GET /accounts/{id}`, `POST /accounts/{id}/deposits`, `POST /transfers`, `GET /transfers/{id}`, `GET /accounts/{id}/statement`. Errors as **RFC 9457 problem+json**. Simple API-key auth via header. Request validation. *Done:* OpenAPI validates; contract test asserts responses match schema.

**`add-observability`** — see §7.
**`add-deployment`** — see §7.
**`add-ci`** — see §7.

> After the functional changes, optionally add **`add-llm-statement-query`**: a natural-language query over the statement read model (`GET /accounts/{id}/statement?q="how much went to X in March"`) calling the Anthropic API to translate NL → a structured filter over the projection. Keep it small and behind a feature flag; it signals AI-native instinct without polluting the core domain.

---

## 6. Cross-cutting requirements

- **Concurrency:** every aggregate write uses expected-version optimistic locking; conflicts surface as a retriable `409`. Include a test that fires two concurrent transfers from the same account with funds for only one and asserts exactly one succeeds.
- **Event versioning:** events carry a schema version; provide an **upcaster** mechanism and at least one example upcaster (e.g. `AccountOpened` v1→v2 adding a field). Document why in an ADR.
- **Correlation:** every command carries a correlation/causation id propagated into events, logs, and traces.
- **Time:** inject a `Clock`; no `new DateTime()` in domain.
- **Determinism:** projectors and sagas must be replay-safe and side-effect-free except through ports.

---

## 7. Infrastructure & ops wrapping ("обвязка")

### `add-observability`
- **Health:** `/healthz` (liveness, no deps), `/readyz` (checks DB + outbox relay reachable).
- **Metrics (Prometheus):** expose via RoadRunner metrics plugin on a separate port.
  - Runtime/RR metrics (workers, queue depth, request latency histograms).
  - **Business metrics:** `transfers_total{status}`, `holds_active`, `journal_entries_total`, `projection_lag_seconds`, `outbox_pending`, `idempotency_replays_total`.
- **Tracing:** OpenTelemetry, spans across HTTP → command → event append → outbox → projection, trace id in logs.
- **Logging:** structured JSON to stdout, includes trace/correlation id, level via env.
- **Deliverables:** a **Grafana dashboard JSON** (golden signals + the business metrics) and **Prometheus alert rules** (e.g. `projection_lag_seconds > 30` for 5m, `outbox_pending` growing, p99 latency SLO burn).

### `add-deployment`
- **Dockerfile:** multi-stage (composer install → minimal runtime with RoadRunner binary + PHP). Non-root user. Image runs both the HTTP server and, via a different command, the worker process.
- **docker compose (local dev):** `app`, `worker`, `postgres`, `prometheus`, `grafana`, `otel-collector`. Includes a `migrate` step and a **seed command** that creates demo accounts and a few transfers, so `docker compose up` gives a working, populated system.
- **Kubernetes (prefer a Helm chart under `deploy/helm/ledger-core`):**
  - Separate `Deployment`s for **api** and **worker** (outbox relay + projectors).
  - `Service`, `ConfigMap`, `Secret` (DB creds, API key), resource `requests`/`limits`.
  - **Liveness/readiness/startup probes** wired to `/healthz` and `/readyz`.
  - **HPA** on api (CPU + a custom metric `projection_lag_seconds` for the worker if a metrics adapter is available; otherwise CPU).
  - **DB migrations as a Helm pre-install/pre-upgrade `Job`** (or init container) — never on app boot.
  - **`ServiceMonitor`** (Prometheus Operator) or pod scrape annotations.
  - `PodDisruptionBudget` and graceful shutdown (drain RR workers, finish in-flight jobs) — important for a saga/worker system.
  - A **`kind`/minikube quickstart** in the README.

### `add-ci` (GitHub Actions)
Pipeline stages, each gating the next:
1. **Lint:** `php-cs-fixer --dry-run`, `composer validate`.
2. **Static analysis:** PHPStan level max (and/or Psalm).
3. **Spec gate:** `openspec validate --strict` over all active changes — **fail the build if specs are invalid.**
4. **Unit tests** (PHPUnit).
5. **Integration tests** with a `postgres` service container (event store, projections, outbox).
6. **Mutation testing** (Infection) on the domain layer — optional, can be non-blocking with a min MSI threshold.
7. **Container build** (buildx), push to GHCR on `main`.
8. **(Optional CD smoke):** spin up `kind`, `helm install`, run seed + one e2e transfer against the cluster, tear down.

Cache composer; run jobs in a matrix only if it adds value. Keep the spec-validate gate early and cheap.

---

## 8. Principal-level artifacts (do not skip — this is the differentiator)

Produce these as real files in the repo:

- **ADRs** (`docs/adr/`), each with *Context / Decision / Consequences / **Options considered and rejected***:
  - ADR-001 — Event Sourcing for the ledger (rejected: CRUD + audit table, CDC).
  - ADR-002 — Transactional outbox vs dual-write vs CDC for publication.
  - ADR-003 — Async projections vs synchronous read-after-write; consistency trade-off.
  - ADR-004 — **Where we deliberately avoid ES** (idempotency store, projections as plain tables) and why over-applying ES is a cost, not a virtue.
  - ADR-005 — Saga orchestration vs choreography for transfers.
  - ADR-006 — Event versioning / upcasting strategy.
- **`docs/design.md`** — a written design doc that *anticipates objections*: an "Options considered and rejected" section, a **brownfield evolution path** ("if a legacy mutable-balance system with millions of postings existed, how would we migrate to this with dual-write and rollback, no downtime"), a **100x scaling analysis** (what breaks first — event store write throughput, projection lag, outbox relay — and the mitigation), and a short **cost / on-call** note.
- **`docs/runbook.md`** — operational playbook: rebuild a projection, drain a stuck saga, handle outbox backlog, what each alert means.
- **`docs/slo.md`** — SLOs (e.g. transfer p99 latency, projection lag, availability) tied to the alert rules.

---

## 9. Definition of done

- `docker compose up` → seeded, working system; an e2e transfer succeeds end to end.
- All OpenSpec changes archived; `openspec validate --strict` clean.
- Unit + integration tests green in CI; PHPStan max clean.
- Helm chart installs on `kind`; probes pass; metrics scraped; Grafana dashboard renders.
- ADRs + design doc + runbook present and substantive (not stubs).
- README: architecture diagram (text/mermaid ok), bounded-context map, "how to run", "how to rebuild a projection", and an honest "what I deliberately did NOT build and why".

---

## 10. Kickoff prompt (paste this into Claude Code first)

> You are implementing the `ledger-core` project defined in `ledger-core-brief.md` (this file is in the repo root). Work strictly through OpenSpec.
>
> Step 1: run `openspec init` if needed, then write `openspec/project.md` from §1–§3 of the brief. Stop and show me `project.md` for review before proceeding.
>
> Step 2: create the **first** change only — `add-event-store-foundation` — as a full OpenSpec change (proposal.md, design.md, tasks.md, spec delta). Run `openspec validate --strict` on it. Do **not** write implementation code yet. Show me the change for review.
>
> Proceed one change at a time in the order of §5, pausing for review after each proposal and after each implementation. Never bundle capabilities. Treat `openspec validate --strict` as a hard gate.
