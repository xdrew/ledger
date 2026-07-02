## Why

The ledger has an API, a worker's worth of console commands, and observability, but no way to ship
it: no production image, no worker runtime, no local "up and populated" stack, and no Kubernetes
manifests. This change adds the **deployment wrapping** from brief §7 — a production multi-stage
image that runs either the API or the worker, a continuous worker loop, a full local `docker
compose` stack (with migrate + seed), and a Helm chart with separate api/worker deployments, probes,
autoscaling, a migration job, scrape config, and graceful shutdown.

## What Changes

- **Production image** (`docker/php/Dockerfile.prod`, multi-stage): a builder stage runs
  `composer install --no-dev`, a slim runtime stage carries PHP 8.5 + the `rr` binary +
  `ext-pdo_pgsql`/`ext-sockets`, runs as a **non-root** user, and bundles the code + vendored deps.
  One image, two roles selected by command: **api** (`rr serve -c .rr.yaml`) and **worker**
  (`rr serve -c .rr-worker.yaml`).
- **Worker runtime** (`.rr-worker.yaml`): a RoadRunner process (no HTTP) that runs the **outbox
  relay**, the **projection catch-up**, and **`metrics:collect`** on continuous loops as RR
  services, exposing the metrics plugin. A `--loop` option is added to `outbox:relay` and
  `projections:run` so the loop lives in PHP (clean shutdown) rather than a shell `while`.
- **Local stack** (`docker-compose.yml` extended): `postgres`, `api`, `worker`, `prometheus`,
  `grafana`, `otel-collector`, plus a one-shot `migrate` service and a **seed** step, so
  `docker compose up` yields a **migrated, populated, working** system. Prometheus scrapes the api
  and worker metrics ports; Grafana is provisioned with the committed dashboard.
- **Seed command** (`app:seed`): creates a handful of demo accounts and transfers through the
  command bus, so a fresh stack has data to look at (and the read models are caught up).
- **Helm chart** (`deploy/helm/ledger-core`):
  - Separate **`api`** and **`worker`** `Deployment`s, a `Service`, a `ConfigMap` (non-secret
    config), a `Secret` (DB URL, API key), and resource `requests`/`limits`.
  - **Startup/liveness/readiness probes** wired to `/healthz` and `/readyz`.
  - **HPA** on the api (CPU) and the worker (`projection_lag_seconds` custom metric when a metrics
    adapter is available, else CPU).
  - **DB migrations as a pre-install/pre-upgrade `Job`** (Helm hook) — never on app boot.
  - **`ServiceMonitor`** (Prometheus Operator) with a pod-annotation fallback.
  - **`PodDisruptionBudget`** and **graceful shutdown** (termination grace period + a `preStop`
    `rr stop` so in-flight requests/jobs drain) — important for the saga/worker.
  - A **kind/minikube quickstart** in the `README`.
- **Tracing export** (resolves the observability deferral): the PHP OTLP exporter installs by pinning
  `google/protobuf:^4.31.1` (common to the OTel exporter and the RoadRunner packages). `TracerFactory`
  now exports spans over OTLP/HTTP to the `otel-collector` when tracing is enabled with an endpoint.

## Capabilities

### New Capabilities
- `deployment`: the production image (api + worker roles), the continuous worker runtime, the local
  compose stack (migrate + seed + prometheus/grafana/otel-collector), the seed command, and the
  Helm chart (separate deployments, probes, HPA, migration job, ServiceMonitor, PDB, graceful
  shutdown) with a kind quickstart.

### Modified Capabilities
<!-- None at the spec level; composes existing capabilities. The `--loop` options are additive. -->

## Impact

- **New code:** `App\Console\SeedCommand` (`app:seed`); `--loop`/`--interval` options on
  `outbox:relay` and `projections:run`.
- **New files:** `docker/php/Dockerfile.prod`, `.rr-worker.yaml`, `docker/prometheus/prometheus.yml`,
  `docker/grafana/**` (datasource + dashboard provisioning), `docker/otel-collector/config.yaml`,
  `deploy/helm/ledger-core/**` (Chart, values, templates), README quickstart; `docker-compose.yml`
  gains `worker`/`prometheus`/`grafana`/`otel-collector`/`migrate` services.
- **Verification here vs. cluster:** the image build, `docker compose up` smoke (migrate + seed +
  `/healthz` + metrics scrape), and the seed command are verified in this environment;
  `helm lint` + `helm template` validate the chart. A live `kind` install (probes pass, metrics
  scraped, dashboard renders) is the documented quickstart for a machine with a cluster.
- **Depends on** the api and observability capabilities (probes, metrics, `.rr` configs). The
  §8 artifacts (`docs/runbook.md`, `docs/slo.md`, ADRs) are produced separately.
