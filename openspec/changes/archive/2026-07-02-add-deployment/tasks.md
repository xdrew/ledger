> Depends on api + observability (probes, metrics, `.rr` configs). New files: production Dockerfile,
> `.rr-worker.yaml`, compose services, prometheus/grafana/otel-collector config, Helm chart. The
> §8 docs (runbook, slo, ADRs) and CI are separate. k8s is validated via helm lint/template here; a
> live kind install is the README quickstart.

## 1. Production image

- [x] 1.1 `docker/php/Dockerfile.prod`: multi-stage (builder `composer install --no-dev`, slim runtime with PHP 8.5 + `rr` + `pdo_pgsql` + `sockets`), non-root `app` user, copy code + vendor + `.rr*.yaml`.
- [x] 1.2 Entrypoint dispatching roles: `api` → `rr serve -c .rr.yaml`, `worker` → `rr serve -c .rr-worker.yaml`, `migrate` → `doctrine:migrations:migrate`, `console` → passthrough.
- [x] 1.3 Verify the image builds and `api`/`migrate` run.

## 2. Worker runtime

- [x] 2.1 `--loop`/`--interval` options on `outbox:relay` and `projections:run` (PHP-side loop, SIGTERM-aware between iterations).
- [x] 2.2 `.rr-worker.yaml`: RR services for `outbox:relay --loop`, `projections:run --loop`, `metrics:collect`; metrics + rpc plugins; no HTTP.

## 3. Local compose stack

- [x] 3.1 Add `migrate` (one-shot) and `seed` (one-shot, after migrate) services; `api` + `worker` depend on `migrate`.
- [x] 3.2 Add `prometheus` (`docker/prometheus/prometheus.yml` scraping `api:2112` + `worker:2112`), `grafana` (provisioned datasource + the committed dashboard), `otel-collector` (`docker/otel-collector/config.yaml`).
- [x] 3.3 `docker compose up` yields a migrated, seeded, working system; smoke `/healthz` + a metrics scrape.

## 4. Seed command

- [x] 4.1 `App\Console\SeedCommand` (`app:seed`): open demo accounts, deposit, run transfers (incl. one failed), then catch up projections; safe to re-run.

## 5. Helm chart (`deploy/helm/ledger-core`)

- [x] 5.1 `Chart.yaml`, `values.yaml`, `_helpers.tpl`; `deployment-api.yaml` + `deployment-worker.yaml` (separate), `service.yaml`, `configmap.yaml`, `secret.yaml`, resource requests/limits.
- [x] 5.2 Probes: startup + liveness + readiness → `/healthz` / `/readyz` (api); a lightweight worker liveness.
- [x] 5.3 `hpa-api.yaml` (CPU) + `hpa-worker.yaml` (`projection_lag_seconds` custom metric via flag, else CPU).
- [x] 5.4 `migrate-job.yaml` as a `pre-install,pre-upgrade` Helm hook (never migrate on boot).
- [x] 5.5 `servicemonitor.yaml` (values-flagged) + pod scrape annotations; `pdb.yaml`; graceful shutdown (`terminationGracePeriodSeconds` + `preStop` `rr stop`).
- [x] 5.6 README `kind`/minikube quickstart.

## 6. Tracing export (D6)

- [x] 6.1 `open-telemetry/exporter-otlp` installed pinned with `google/protobuf:^4.31.1`; `TracerFactory` exports OTLP/HTTP to `otel-collector`; verified live (command + projection spans share a trace id).

## 7. Tests & gate

- [x] 7.1 Integration test: `app:seed` creates accounts and transfers; the read models reflect them.
- [x] 7.2 Helm: `helm lint` passes; `helm template` renders both deployments, the migration hook Job, and the probes (asserted).
- [x] 7.3 Compose smoke: build the prod image, `docker compose up`, assert `/healthz` 200 and business/runtime metrics on `:2112`.
- [x] 7.4 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration + functional suites; `openspec validate add-deployment --strict` passes.
