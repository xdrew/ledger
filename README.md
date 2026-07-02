# ledger-core

An event-sourced payment ledger — the backend internals of a wallet / neobank-style
service. Built for **correctness of money under concurrency**: no lost updates, no
double-spend, full auditability. Backend only (no UI).

Work proceeds strictly through [OpenSpec](https://github.com/Fission-AI/OpenSpec); see
`openspec/project.md` for vision, stack, and architecture, and `openspec/changes/` for the
change-by-change plan.

> **Status:** all functional and ops capabilities are built and archived — event store,
> accounts, double-entry ledger, transfer saga, idempotency, projections, outbox, message
> bus, HTTP API (RoadRunner), observability, deployment (compose + Helm), CI. Docs:
> [ADRs](docs/adr/) · [design](docs/design.md) · [runbook](docs/runbook.md) · [SLOs](docs/slo.md).

## Stack

- PHP 8.5, Symfony 8, RoadRunner (HTTP + worker host), thesis/message-bus (in-process commands)
- PostgreSQL 18, Doctrine DBAL (no ORM on the write side), Doctrine Migrations (always generated)
- Prometheus + Grafana + OpenTelemetry; Docker/Helm for packaging
- Everything runs in Docker — no PHP/Composer needed on the host.

## Architecture

```mermaid
flowchart LR
    C[Client] -->|X-Api-Key + Idempotency-Key| API

    subgraph API [api — RoadRunner]
        A[Action controllers] -->|commands| CB[CommandBus]
        A -->|queries| RM
    end

    CB --> ORCH[Transfer saga<br/>hold → post → settle]
    CB --> ES[(events<br/>append-only log)]
    ORCH --> ES

    subgraph WORKER [worker — RoadRunner]
        REL[Outbox relay] --> PUB[pg NOTIFY / NATS]
        PROJ[Projections] --> RM[(read models)]
    end

    ES -->|tail from checkpoint| REL
    ES -->|tail from checkpoint| PROJ
```

The write side is **event-sourced** (append-only `events` table; UNIQUE stream+version as the
optimistic-concurrency guard — ADR-001); the event log doubles as the **transactional outbox**
(ADR-002); reads come from **rebuildable projections** and are eventually consistent, with the
projection `version` exposed on responses (ADR-003); transfers run as an **orchestrated saga**
whose only compensation is releasing the hold (ADR-005).

**Bounded contexts** (`src/`): `Accounts` (balances, holds, lifecycle) · `Ledger` (double-entry
journal, balanced legs) · `Transfers` (the saga) · `SharedKernel` (Money, ids, clock) — plus
infrastructure capabilities: `EventStore`, `Idempotency`, `Projections`, `Outbox`, `Messaging`,
`Api`, `Observability`, `Ops`. Contexts interact through events and ports (e.g. the ledger checks
account status via `AccountStatusReader`), never through each other's internals.

## How to run

Tasks are driven by [Task](https://taskfile.dev) (`Taskfile.yml`); everything runs in
Docker, so no PHP/Composer on the host:

```bash
task up        # build + start PostgreSQL 18 and the app container
task install   # composer install (inside the container; incl. vendor-bin tools)
task db-ping   # verify database connectivity
task migrate   # apply migrations (always generated via the schema provider, never hand-written)
task test      # unit + integration + functional suites
task down      # stop and wipe volumes
```

`task --list` shows all targets. Without Task, the equivalents are
`docker compose up -d --build`, `docker compose exec app composer install`,
`docker compose exec app php bin/console …`, and `docker compose exec app composer test`.

## Run the full stack locally

`task up:stack` (or `docker compose up -d --build migrate seed api worker prometheus grafana
otel-collector`) brings up the production-image **api** and **worker**, runs migrations and a
**seed** step, and starts Prometheus, Grafana, and an OpenTelemetry collector. Then:

- API: <http://localhost:8080> (`GET /healthz`, `GET /readyz`; business endpoints under `/api`,
  `X-Api-Key: local_dev_api_key`)
- Metrics: the api and worker expose `:2112`; Prometheus at <http://localhost:9090> scrapes both.
- Grafana at <http://localhost:3000> (anonymous admin) with the **Ledger Core** dashboard.
- Traces (enabled in the compose demo) export via OTLP to the collector.

## Deploy to Kubernetes (Helm)

A chart lives at `deploy/helm/ledger-core` — separate **api** and **worker** Deployments, probes
wired to `/healthz` / `/readyz`, an HPA, DB migrations as a pre-install/pre-upgrade Job (never on
boot), a PodDisruptionBudget, and graceful shutdown. Quickstart on a local `kind` cluster:

```sh
kind create cluster --name ledger
# Build the image and load it into the cluster:
docker build -f docker/php/Dockerfile.prod -t ledger-core:prod .
kind load docker-image ledger-core:prod --name ledger
# Bring up PostgreSQL (e.g. Bitnami) and point DATABASE_URL at it, then:
helm install ledger deploy/helm/ledger-core \
  --set secret.databaseUrl="postgresql://ledger:ledger@postgres:5432/ledger?serverVersion=18&charset=utf8" \
  --set secret.apiKey="$(openssl rand -hex 16)" \
  --set secret.appSecret="$(openssl rand -hex 16)"
kubectl port-forward svc/ledger-ledger-core-api 8080:80
```

`helm lint deploy/helm/ledger-core` and `helm template deploy/helm/ledger-core` validate the chart
without a cluster.

## Natural-language statement queries (optional, flag-gated)

With `LLM_STATEMENT_QUERY_ENABLED=1` and a real `ANTHROPIC_API_KEY` in the environment, the
statement endpoint accepts a question:

```sh
curl -H 'X-Api-Key: …' 'localhost:8080/api/accounts/{id}/statement?q=how much did I deposit in June'
```

The Anthropic API (official PHP SDK, structured outputs) translates the question into a typed
filter — entry types, date range, amount range, aggregation — which runs as parameterized SQL over
the `account_statement` read model; `sum`/`count` are computed by the database, never by the model.
Every response echoes the **interpretation** (the applied filter), so you always see how the
question was understood. Flag off → `501`; translation failure → `502` — the endpoint never guesses.
Model via `LLM_MODEL` (default `claude-opus-4-8`; e.g. `claude-haiku-4-5` for cost). Honest limit:
the read model has no counterparty column, so "who" questions filter by direction/date/amount only.
CI never calls the API (a deterministic fake serves the tests); a live smoke is: flag on, key set,
run the curl above.

## How to rebuild a projection

Read models are disposable by design (ADR-003):

```sh
docker compose exec worker php bin/console projections:status    # checkpoint / head / lag
docker compose exec worker php bin/console projections:rebuild   # truncate + replay from 0
```

Safe while the API serves traffic — reads are stale (never wrong) during the replay, and balance
responses carry the projection `version`. Full play (expectations, verification, alert handling):
[docs/runbook.md](docs/runbook.md).

## What is deliberately NOT built (and why)

- **Out of scope (non-goals):** UI/frontend; real banking rails / card networks; KYC; multi-tenant
  auth beyond a single API key (service-to-service backend); FX / cross-currency conversion
  (accounts are single-currency; `Money` refuses mixed-currency arithmetic).
- **Deliberate architectural restraint:** the idempotency store and read models are plain mutable
  tables, *not* event-sourced — over-applying ES is a cost, not a virtue (ADR-004); commands are
  handled synchronously in-process — no async command transport until something needs it
  (queries never touch a bus at all).
- **Known gaps, on the roadmap:** the upcaster mechanism (strategy fixed in ADR-006; lands in
  `add-event-upcasting`); automatic recovery of a saga interrupted mid-flight (currently a runbook
  play — funds park in `reserved`, nothing is lost); the optional `add-llm-statement-query`.
- **Accepted limits at this scale:** single-database availability story (99.5% SLO, `docs/slo.md`);
  serial relay/projection tailers (the 100x partitioning path is designed in `docs/design.md`).

## Continuous integration

`.github/workflows/ci.yml` runs staged jobs, each gating the next:

1. **quality** (every push/PR): OpenSpec strict spec validation (first, before PHP installs) →
   composer validate → lint → PHPStan (max) → unit → integration → functional tests (against a
   PostgreSQL service) → Helm chart lint/template.
2. **mutation** (needs quality): Infection on the domain layer, unit-suite-only, **blocking**
   at `--min-msi=80` (measured ~86%). Locally: `task infection`.
3. **build** (needs quality): the production image builds via buildx on every run; pushes to
   GHCR (`latest` + commit SHA) on `main` using the built-in `GITHUB_TOKEN`.
4. **cd-smoke** (`main` only, needs build): a `kind` cluster gets PostgreSQL and the freshly
   built image, `helm install` runs the migration hook and waits for probes, then the job seeds
   demo data and runs one end-to-end transfer against the in-cluster API, asserting `completed`.
