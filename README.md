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

## How to rebuild a projection

Not applicable yet — projections arrive in `add-projections` (a `projections:rebuild`
console command).

## What is deliberately NOT built (yet / ever)

- **Out of scope (non-goals):** UI/frontend, real banking rails / card networks, KYC,
  multi-tenant auth beyond a simple API key, FX / cross-currency conversion.
- **Deferred to later changes:** HTTP API + RoadRunner, observability (metrics/traces/
  dashboards), deployment (production Dockerfile, Helm), and the full CI pipeline.

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
