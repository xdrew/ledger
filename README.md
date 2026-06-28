# ledger-core

An event-sourced payment ledger — the backend internals of a wallet / neobank-style
service. Built for **correctness of money under concurrency**: no lost updates, no
double-spend, full auditability. Backend only (no UI).

Work proceeds strictly through [OpenSpec](https://github.com/Fission-AI/OpenSpec); see
`openspec/project.md` for vision, stack, and architecture, and `openspec/changes/` for the
change-by-change plan.

> **Status:** project skeleton (`add-project-skeleton`). A runnable PHP 8.5 / Symfony 8
> console app on PostgreSQL 18, with migrations, test suites, and an early CI gate. The
> domain (event store, accounts, ledger, transfers, …) is built in subsequent changes.

## Stack

- PHP 8.5, Symfony 8 (console + DI; HTTP/RoadRunner arrive in `add-http-api`)
- PostgreSQL 18, Doctrine DBAL (no ORM on the write side), Doctrine Migrations
- Everything runs in Docker — no PHP/Composer needed on the host.

## How to run

Tasks are driven by [Task](https://taskfile.dev) (`Taskfile.yml`); everything runs in
Docker, so no PHP/Composer on the host:

```bash
task up        # build + start PostgreSQL 18 and the app container
task install   # composer install (inside the container; incl. vendor-bin tools)
task db-ping   # verify database connectivity
task migrate   # apply migrations (the first one — the events table — lands next change)
task test      # unit + integration suites
task down      # stop and wipe volumes
```

`task --list` shows all targets. Without Task, the equivalents are
`docker compose up -d --build`, `docker compose exec app composer install`,
`docker compose exec app php bin/console …`, and `docker compose exec app composer test`.

## How to rebuild a projection

Not applicable yet — projections arrive in `add-projections` (a `projections:rebuild`
console command).

## What is deliberately NOT built (yet / ever)

- **Out of scope (non-goals):** UI/frontend, real banking rails / card networks, KYC,
  multi-tenant auth beyond a simple API key, FX / cross-currency conversion.
- **Deferred to later changes:** HTTP API + RoadRunner, observability (metrics/traces/
  dashboards), deployment (production Dockerfile, Helm), and the full CI pipeline.

## Continuous integration

`.github/workflows/ci.yml` runs the early/cheap gate on every push and PR: OpenSpec
strict spec validation → composer validate → lint → PHPStan (max) → unit tests →
integration tests (against a PostgreSQL service). The remaining stages (mutation testing,
container build, CD smoke) are added in `add-ci`.
