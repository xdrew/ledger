## Context

An early quality gate exists from the skeleton change but has drifted (missing `ext-sockets` for
the current lockfile; no functional suite) and lacks the later §7 stages (mutation, build/publish,
CD smoke). Constraints from the brief: stages gate each other; the spec gate stays early and cheap;
mutation testing targets the domain and may set a minimum MSI; images publish to GHCR on `main`;
the CD smoke uses kind + the Helm chart.

## Goals / Non-Goals

**Goals:**
- A correct, staged pipeline: spec gate → lint → PHPStan → unit → integration → functional.
- Domain-scoped Infection with a low-but-blocking MSI floor, runnable locally (`task infection`).
- Prod image build on every run; GHCR publish (latest + SHA) on `main`.
- A `main`-only kind CD smoke: helm install → probes green → seed + one e2e transfer.
- Cheap Helm validation (lint/template) in the quality gate.

**Non-Goals:** deploy-to-environment CD (no target env exists); coverage gates (mutation testing is
the stronger signal); matrix builds (one PHP version, one platform — no value in a matrix yet);
Psalm (PHPStan max already gates; the brief says "and/or").

## Decisions

### D1: One workflow, staged jobs
`ci.yml` keeps one workflow with jobs: **quality** (the §7.1–7.5 gate, extended with the functional
suite and helm lint/template), **mutation** (needs: quality), **build** (needs: quality; buildx +
GHA cache; push to GHCR only on `main`), **cd-smoke** (needs: build; `main` only). Jobs gate via
`needs`, matching "each stage gating the next" without splitting into multiple workflow files.

- *Alternatives rejected:* separate workflow files per stage (harder to express gating); a reusable
  workflow (overkill for one repo).

### D2: Extensions and the functional suite in the gate
`setup-php` installs `pdo_pgsql, sockets, pcntl` (what the lockfile enforces). The functional suite
runs after integration against the same `postgres:18` service (`ledger_test` DB, as today). `.env`
supplies non-secret defaults (API key, TTLs); CI env overrides `DATABASE_URL`.

### D3: Infection scoped to the domain with a blocking floor
`infection.json5` mutates `src/SharedKernel`, `src/EventStore`, `src/Accounts/Domain`,
`src/Ledger/Domain`, `src/Transfers/Domain`, `src/Idempotency` — the pure logic the unit suite
covers — using the **unit suite only** (fast, no DB). Threshold: `--min-msi=70` initially
(calibrated at implementation from the real score, set a few points below it to block regressions,
not to flake). Runs as its own CI job and locally via `task infection`. Installed through
`vendor-bin/tools` like the other dev tools.

- *Alternatives rejected:* non-blocking mutation (report-only rots); mutating the whole `src/`
  (infrastructure mutants are noisy and slow — DBAL adapters die to integration tests, not units).

### D4: Build/publish via buildx + GHCR
`docker/build-push-action` builds `docker/php/Dockerfile.prod` with `cache-from/to: gha`. On PRs:
build only (`push: false`) — proves the multi-stage image assembles. On `main`: login with
`GITHUB_TOKEN` (`packages: write`) and push `ghcr.io/<owner>/ledger-core:{latest,sha}`.

### D5: CD smoke on kind (`main` only)
Steps: `helm/kind-action` creates a cluster → install PostgreSQL (bitnami chart, ephemeral) →
`kind load` the image built in this run → `helm install` `deploy/helm/ledger-core` (migration hook
runs) → `kubectl rollout status` both deployments (probes must go ready) → port-forward the api →
run the seed via `console` role + POST one transfer with curl and assert `"status":"completed"`.
The job is `main`-only to keep PR feedback fast; it is the live proof the chart + probes + image
actually work together (they cannot be exercised in the dev environment).

### D6: Local parity
Every gate command exists as a Task target (`task lint analyse test spec infection`), so the
pipeline is reproducible outside Actions. The implementation runs each of them here before the
workflow lands.

## Risks / Trade-offs

- **Actions-only steps unverifiable locally** (GHCR login, kind action) → Mitigation: standard
  actions with pinned majors; every shell command inside jobs is executed locally first; the first
  push exercises the rest.
- **Infection flakiness/duration** → Mitigation: domain-only scope + unit-suite-only + threads;
  the floor set below the measured MSI.
- **kind smoke duration on main** → Mitigation: `main`-only, single node, ephemeral PostgreSQL,
  no retries beyond rollout timeouts.
- **PHP 8.5 availability in setup-php** → already proven by the existing workflow.

## Open Questions

- The measured domain MSI (sets the exact `--min-msi`) — resolved at implementation.
- Whether `actionlint` is available locally for workflow linting — best-effort; YAML parse check
  otherwise.
