## Context

Everything to run the ledger exists except the packaging: a shippable image, a worker runtime, a
one-command local stack, and Kubernetes manifests. This change wires those, composing the api and
observability capabilities (probes at `/healthz` `/readyz`, metrics on `:2112`, the `.rr` configs).
Constraints from brief §7: multi-stage non-root image; one image runs both the HTTP server and the
worker via different commands; migrations run as a job, never on boot; separate api/worker
deployments; graceful shutdown for the saga/worker.

## Goals / Non-Goals

**Goals:**
- A production multi-stage image (non-root, RR binary) that runs **api** or **worker** by command.
- A continuous worker (relay + projections + metrics collect) with clean shutdown.
- `docker compose up` → migrated + seeded + observable stack.
- A Helm chart: separate deployments, probes, HPA, migration job, ServiceMonitor, PDB, graceful
  shutdown; a kind quickstart.

**Non-Goals (later / elsewhere):** `docs/runbook.md`, `docs/slo.md`, ADRs (§8 artifacts); a managed
cloud install; multi-region; secret management beyond a k8s `Secret`; CI (that is `add-ci`).

## Decisions

### D1: Multi-stage, non-root image; one image, two roles
`docker/php/Dockerfile.prod`: a **builder** stage (`composer install --no-dev --classmap-authoritative`,
warm the prod cache) and a slim **runtime** stage (`php:8.5-cli` + `pdo_pgsql` + `sockets` + the `rr`
binary), copying `vendor/`, `src/`, `config/`, `public/`, `bin/`, and the `.rr*.yaml`. A non-root
`app` user owns `/app` and `var/`. An entrypoint dispatches the role: `api` →
`rr serve -c .rr.yaml`, `worker` → `rr serve -c .rr-worker.yaml`, `migrate` →
`bin/console doctrine:migrations:migrate --no-interaction`, `console` → passthrough. The dev image
(`Dockerfile`) stays for local tooling/tests.

- *Alternatives rejected:* separate api/worker images (one image is simpler to build/scan/promote);
  PHP-FPM (the project standardized on RoadRunner).

### D2: Worker runtime as RoadRunner services with PHP-side loops
`.rr-worker.yaml` runs no HTTP; it hosts three `service`s — `outbox:relay --loop`,
`projections:run --loop`, and `metrics:collect` — plus the metrics + rpc plugins. The `--loop`
option (with `--interval`, default ~1s for relay/projections) moves the loop into PHP so a SIGTERM
is handled between iterations (clean shutdown, no half-processed batch). RoadRunner supervises and
restarts them. Both api and worker expose `:2112`, scraped as separate targets.

- *Alternatives rejected:* a shell `while true; sleep` (a SIGTERM mid-`sleep` is abrupt; PHP-side
  loops drain cleanly); the RR `jobs` plugin (overkill — the relay already tails the log with a
  checkpoint; a loop is enough for the demo). LISTEN/NOTIFY-driven wakeups are a later optimization.

### D3: Local compose stack — migrated, seeded, observable
`docker-compose.yml` gains: a one-shot **`migrate`** service (runs migrations, exits 0), a **`seed`**
one-shot (depends on `migrate`, runs `app:seed`), **`api`** and **`worker`** (depend on `migrate`),
**`prometheus`** (scrapes `api:2112`, `worker:2112`), **`grafana`** (provisioned datasource +
the committed dashboard), and **`otel-collector`**. `docker compose up` yields a working, populated
system; ordering is via `depends_on` + healthchecks. Host ports are overridable and defaulted to
avoid clashes.

### D4: Seed command
`app:seed` opens a few demo accounts (mixed currencies), deposits, and runs several transfers
(including one that fails on insufficient funds) through the `CommandBus`, then runs the projection
catch-up so the read models are populated. It is safe to re-run (fresh ids each time) and prints
what it created. Covered by an integration test asserting accounts/transfers exist afterwards.

### D5: Helm chart `deploy/helm/ledger-core`
Templates: `deployment-api.yaml`, `deployment-worker.yaml`, `service.yaml` (api), `configmap.yaml`
(non-secret env), `secret.yaml` (`DATABASE_URL`, `API_KEY`), `hpa-api.yaml`, `hpa-worker.yaml`,
`migrate-job.yaml` (Helm `pre-install,pre-upgrade` hook), `servicemonitor.yaml` (guarded by a
values flag; pods also carry Prometheus scrape annotations), `pdb.yaml`, and `_helpers.tpl`.
- **Probes:** `startupProbe` + `livenessProbe` → `/healthz`, `readinessProbe` → `/readyz` (api;
  the worker uses an exec/`/healthz`-less liveness since it has no HTTP — a lightweight TCP/`rr`
  check).
- **HPA:** api on CPU; worker on the `projection_lag_seconds` custom metric when the cluster has a
  metrics adapter (values flag), else CPU.
- **Migrations:** the pre-upgrade `Job` runs `migrate`; `helm.sh/hook-weight` orders it before the
  rollout; the app containers never migrate on boot.
- **Graceful shutdown:** `terminationGracePeriodSeconds` + a `preStop` `rr stop` so RoadRunner
  drains in-flight requests / finishes the current worker iteration.
- **Verification:** `helm lint` and `helm template` (rendered manifests are asserted to contain the
  two deployments, the hook job, and the probes) run here; a live `kind` install is the README
  quickstart.

- *Alternatives rejected:* raw manifests / Kustomize (the brief prefers a Helm chart); running
  migrations via an init container (a Helm hook Job decouples them from every pod start).

### D6: Tracing export / protobuf reconciliation
add-observability deferred the PHP OTLP exporter because `open-telemetry/exporter-otlp` needs
`google/protobuf ^3||^4` while the RoadRunner packages pulled `protobuf 5`. This change **attempts**
`composer require open-telemetry/exporter-otlp` while pinning `google/protobuf:^4` (the RoadRunner
2025 packages allow `^4||^5`). If it resolves, `OtelTracer`'s exporter becomes OTLP →
`otel-collector` (endpoint from env) and a smoke run shows spans arriving. If the pin cannot satisfy
both, the collector stays wired for future use and PHP→collector export remains the single
documented deferral — tracing behaviour is otherwise unchanged.

## Risks / Trade-offs

- **k8s not testable in this environment** → Mitigation: `helm lint`/`template` + rendered-manifest
  assertions here; a documented `kind` quickstart for a cluster-equipped machine.
- **Worker with no HTTP has no `/healthz`** → Mitigation: a lightweight liveness (process/rr check);
  readiness of the *system* is still the api's `/readyz` (which checks the relay heartbeat).
- **Seed on every `compose up`** → Mitigation: seed is a one-shot that creates fresh ids; re-running
  adds more demo data rather than failing (documented).
- **Image size / cold start** → Mitigation: `--no-dev` + authoritative classmap; slim runtime base.

## Open Questions

- Worker HPA custom metric plumbing (Prometheus Adapter vs. KEDA) — expose the flag; pick in a real
  cluster.
- Whether `protobuf:^4` satisfies the installed RoadRunner packages — resolved empirically at
  implementation (D6).
- Grafana provisioning format version — track the running Grafana image.
