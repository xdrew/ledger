## Why

CI stops at an early gate written back in the skeleton change: spec validation, lint, PHPStan, and
the unit + integration suites. It is now **stale** (it installs only `pdo_pgsql`, but composer now
requires `ext-sockets`; the functional suite is never run) and **incomplete** against brief §7:
no mutation testing, no container build/publish, no CD smoke. This change brings the pipeline to
its final §7 shape — each stage gating the next, spec gate early and cheap.

## What Changes

- **Fix and extend the quality gate** (`.github/workflows/ci.yml`):
  - PHP extensions updated to what the lockfile actually needs (`pdo_pgsql`, `sockets`, `pcntl`).
  - The **functional suite** (WebTestCase HTTP tests) joins unit + integration, against the same
    PostgreSQL service container.
  - Stage order per §7, fail-fast: spec gate → composer validate → lint → PHPStan → unit →
    integration → functional. Composer cached (already present).
- **Mutation testing (Infection)** on the domain layer (`src/*/Domain`, `src/SharedKernel`,
  `src/EventStore`): a separate job, **blocking with a modest minimum MSI** threshold via
  `--min-msi` (brief allows non-blocking; a low-but-real floor catches gutted tests without
  flaking). Infection is added to the `vendor-bin/tools` namespace; a `task infection` target runs
  it locally.
- **Container build & publish**: a `build` job needing the quality gate — `docker/setup-buildx` +
  `docker/build-push-action` on `docker/php/Dockerfile.prod`, with GHA layer caching. On `main`
  pushes the image is **pushed to GHCR** (`ghcr.io/<owner>/ledger-core`) tagged `latest` + the
  commit SHA; on PRs it only builds (no push).
- **CD smoke** (optional per brief — included as a `main`-only job): create a `kind` cluster,
  install PostgreSQL, `kind load` the built image, `helm install` the chart, wait for readiness
  (probes green), then run the seed and one end-to-end transfer against the in-cluster API and
  assert `completed`. Tears down with the runner.
- **Helm validation** joins the quality gate cheaply: `helm lint` + `helm template` (no cluster).

## Capabilities

### New Capabilities
- `ci`: the GitHub Actions pipeline — staged quality gate (spec validation, lint, static analysis,
  unit/integration/functional tests), domain mutation testing with an MSI floor, container
  build/publish to GHCR on main, and a kind-based CD smoke installing the Helm chart and running an
  e2e transfer.

### Modified Capabilities
<!-- None at the spec level. -->

## Impact

- **Changed files:** `.github/workflows/ci.yml` (extended/split into jobs); `vendor-bin/tools/composer.json`
  (+`infection/infection`); `infection.json5` (domain-scoped mutants, MSI threshold); `Taskfile.yml`
  (+`infection` target); README CI section updated.
- **Verification here vs. GitHub:** workflow YAML is validated (parse + `actionlint` if available)
  and every command a job runs (lint, PHPStan, all three suites, Infection, `helm lint`/`template`,
  the prod image build) is executed locally in this environment; the Actions-only pieces (GHCR
  push, kind job) are exercised on the first push to GitHub.
- **Secrets/permissions:** GHCR push uses the built-in `GITHUB_TOKEN` (`packages: write`) — no new
  secrets.
- **Depends on** the deployment capability (prod Dockerfile, Helm chart, seed) and everything the
  gate runs. This is the last §5/§7 change; the §8 artifacts (ADRs, docs) follow separately.
