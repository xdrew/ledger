> Extends the existing early gate to the full brief §7 pipeline. New dev tool: infection/infection
> (vendor-bin/tools). GHCR push + kind smoke are Actions-only (first push exercises them); every
> in-job shell command is run locally before landing.

## 1. Quality gate (fix + extend)

- [ ] 1.1 Update `setup-php` extensions to `pdo_pgsql, sockets, pcntl`; keep the spec gate first and composer caching.
- [ ] 1.2 Add the functional suite after integration (same postgres service); keep fail-fast stage order: spec → composer validate → lint → PHPStan → unit → integration → functional.
- [ ] 1.3 Add cheap Helm validation to the gate: `helm lint` + `helm template` on `deploy/helm/ledger-core`.

## 2. Mutation testing

- [ ] 2.1 Add `infection/infection` to `vendor-bin/tools`; `infection.json5` scoped to the domain (SharedKernel, EventStore, Accounts/Ledger/Transfers Domain, Idempotency), unit suite only.
- [ ] 2.2 Measure the real MSI; set `--min-msi` a few points below it (blocking). `task infection` target.
- [ ] 2.3 `mutation` job in CI (needs: quality).

## 3. Container build & publish

- [ ] 3.1 `build` job (needs: quality): buildx + GHA layer cache, builds `docker/php/Dockerfile.prod` on every run.
- [ ] 3.2 On `main`: login to GHCR with `GITHUB_TOKEN` (`packages: write`) and push `latest` + SHA tags.

## 4. CD smoke (main only)

- [ ] 4.1 `cd-smoke` job (needs: build, `main` only): kind cluster → ephemeral PostgreSQL → `kind load` the image → `helm install` → `kubectl rollout status` (probes green).
- [ ] 4.2 Seed via the image's `console` role; POST one transfer against the port-forwarded api; assert `"status":"completed"`; teardown with the runner.

## 5. Docs & verification

- [ ] 5.1 Update the README CI section to the full pipeline.
- [ ] 5.2 Run every in-job command locally: lint, PHPStan, all three suites, infection, helm lint/template, prod image build. Workflow YAML parses (actionlint if available).
- [ ] 5.3 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration + functional suites; `openspec validate add-ci --strict` passes.
