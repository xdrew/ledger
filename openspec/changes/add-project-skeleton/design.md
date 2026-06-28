## Context

This is the bootstrap change. It exists because of a sequencing decision: the brief's §5
order puts deployment/observability/CI last, which would leave domain code unrunnable for
most of the project. We instead build a thin **walking skeleton** first — enough runtime
to configure the app, reach PostgreSQL, migrate the schema, and run tests locally — while
keeping the heavy production wrapping (Helm, Grafana, OTel, HPA, RoadRunner HTTP) in its
dedicated later changes. The skeleton must not pre-build things it cannot yet justify.

Constraints from `project.md`: PHP 8.5, Symfony 8 (kernel, DI, console), PostgreSQL 18,
Doctrine DBAL on the write side (no ORM), `declare(strict_types=1)`, PSR-12, PHPStan max,
migrations never run on app boot (deployment principle).

## Goals / Non-Goals

**Goals:**
- A runnable Symfony 8 console application with a DI container.
- Configuration entirely from the environment; fail fast on missing required values.
- A DBAL connection to PostgreSQL 18 as a DI service, shared by runtime and tests.
- Versioned, explicit, idempotent, rollback-capable migrations (console command).
- `docker compose` bringing up PostgreSQL 18 for local dev and integration tests.
- Test + quality tooling (PHPUnit, PHPStan max, CS-Fixer) and an early CI gate.

**Non-Goals (owned by later changes):**
- HTTP server, controllers, RoadRunner, OpenAPI → `add-http-api`.
- `/healthz` / `/readyz` endpoints, metrics, tracing, dashboards → `add-observability`.
- Production Dockerfile, Helm chart, Kubernetes → `add-deployment`.
- Full CI pipeline (integration tests, mutation, container build, CD smoke) → `add-ci`.
- The `events` table and any domain schema → `add-event-store-foundation` and later.
- The message bus (`thesis/message-bus`) and any async runtime → later changes.

## Decisions

### D1: Symfony 8 now, but console-only (no HTTP/RoadRunner)
We wire the Symfony kernel, DI container, and console application immediately, because the
migration command and all future CLI tooling (projection rebuild, outbox relay, seed) live
there, and standing the framework up later would mean reworking config wiring. We
deliberately omit the HTTP stack and RoadRunner until `add-http-api`, so the skeleton stays
thin and the first runnable artifact is a console app + a database.

- *Alternatives rejected:* (a) Framework-less scripts now, Symfony later — guarantees a
  later rewrite of config/DI/console wiring. (b) Full HTTP + RoadRunner now — premature;
  there is no endpoint to serve and it would be reworked against the real API surface.

### D1a: Symfony 8 with the Doctrine Symfony bundles (3.x / 4.x)
Symfony 8.1 and PHP 8.5 are used. The Doctrine Symfony bundles support Symfony 8 from
`doctrine-bundle` ^3.1 (latest 3.2.x) and `doctrine-migrations-bundle` ^4.0, so we use them
idiomatically rather than wiring DBAL/migrations by hand. `doctrine-bundle` provides the
configured `Doctrine\DBAL\Connection` (autowirable) from the env DSN; `doctrine/orm` is not
installed, keeping the write side ORM-free.

- *Note:* the older `doctrine-bundle` 2.x and `migrations-bundle` 3.x lines cap at
  `symfony/console ^7.0`; pinning those (rather than the 3.x/4.x majors) is what makes
  Symfony 8 appear unsupported. The 3.x/4.x majors are the correct choice.

### D2: Configuration strictly from the environment, fail-fast
The DSN, environment, and log level come from environment variables (`.env` for local via
Symfony Dotenv; real env in containers/CI). Required values are validated at startup and a
missing one aborts with a clear error rather than failing deep inside a query later.

- *Alternatives rejected:* committed config files with embedded credentials (insecure,
  breaks 12-factor); lazy/implicit defaults for the DSN (hides misconfiguration until a
  confusing runtime failure).

### D3: Doctrine DBAL connection as a DI service (no ORM)
A single configured DBAL `Connection` is provided through DI and reused by the event store,
migrations, and integration tests. This matches the project's "no ORM for the write side"
rule and gives one place to configure pooling/types later.

- *Alternatives rejected:* Doctrine ORM (violates the write-side rule, unneeded weight);
  ad-hoc PDO (loses DBAL's portability, type handling, and platform abstraction).

### D4: Doctrine Migrations (via doctrine-migrations-bundle) for versioned schema, run explicitly
Schema changes are versioned migration classes applied via a console command
(`doctrine:migrations:migrate`) and reversible (`doctrine:migrations:migrate prev`).
Migrations run only when the command is invoked (CI/deploy job, or local dev) — **never on
application boot**, per the deployment principle. Re-running once everything is applied is a
no-op. We use `doctrine-migrations-bundle` ^4 (see D1a), which registers the
`doctrine:migrations:*` commands and wires the metadata storage to the bundle's connection.

Empty-baseline nuance (learned by running it): Doctrine Migrations treats
`doctrine:migrations:migrate` with **zero** registered migrations as an *error* ("no
registered migrations"), not a no-op. The skeleton ships no domain migrations (the first
one — the `events` table — belongs to `add-event-store-foundation`), so the skeleton
verifies the *mechanism* via `doctrine:migrations:sync-metadata-storage` (initializes
tracking) and `doctrine:migrations:up-to-date` (reports clean), and the concrete apply +
rollback path is exercised once the first real migration lands next change.

- *Alternatives rejected:* a hand-rolled SQL-file runner (reinventing versioning, state
  tracking, and rollback); running migrations automatically on boot (race conditions across
  replicas, violates the explicit-migration principle); ORM schema-diff auto-generation
  (no ORM, and generated DDL is too implicit for a money system).

### D5: `docker compose` for local Postgres; tests target a real database
Local dev and integration tests run against PostgreSQL 18 from `docker compose` (not a
mock/SQLite), because the event store relies on Postgres-specific behavior (JSONB, identity
columns, unique-violation SQLSTATE) that only a real Postgres exercises. Unit tests need no
container; integration tests connect via the same env config.

- *Alternatives rejected:* SQLite/in-memory DB for tests — would not exercise the Postgres
  semantics the event store depends on, giving false confidence.

### D6: Early, cheap CI gate as a deliverable; full `ci` spec deferred
The GitHub Actions workflow added here runs only the fast gate — lint, PHPStan max,
`openspec validate --strict`, unit tests — keeping the spec-validation gate "early and
cheap" as the brief asks. It is created as a project deliverable, not specified as the `ci`
capability; the full pipeline (integration tests, mutation testing, container build, CD
smoke) and its spec belong to `add-ci`, keeping the ci specification consolidated in one
change rather than fragmented across two.

### D7: Dev tools isolated via composer-bin-plugin
PHPStan, PHP-CS-Fixer, and PHPUnit are installed in separate `vendor-bin/<tool>` namespaces
via `bamarni/composer-bin-plugin` (each with its own `composer.json`/`composer.lock` and
`vendor/`), not in the app's `require-dev`. This stops a tool's transitive dependencies —
PHP-CS-Fixer in particular bundles its own Symfony components — from constraining or
colliding with the application's Symfony 8 graph. `bin-links` exposes each tool at
`vendor/bin/<tool>`, and `forward-command` makes a single `composer install` provision the
tools too, so the developer/CI workflow is unchanged.

PHPUnit specifics: it runs *inside* the app runtime (tests boot the kernel via the root
autoloader), so it gets its own namespace but **without `symfony/phpunit-bridge`** — that
package would pull Symfony components that double-load against the app at test time. Because
PHPUnit then lives outside the root autoloader, PHPStan is pointed at
`vendor-bin/phpunit/vendor` (via `scanDirectories`) so it can still resolve `TestCase` when
analysing `tests/`.

- *Alternatives rejected:* keeping all tools in `require-dev` — risks PHP-CS-Fixer's Symfony
  deps conflicting with the app's; PHIVE/phar downloads — adds a separate, unversioned
  toolchain outside Composer's lockfile guarantees.

## Risks / Trade-offs

- **Introducing Symfony before there's much to run could feel heavy** → Mitigation:
  console-only, minimal config; the framework is needed imminently (migrations command) and
  delaying it only defers rework.
- **Tests depend on a running Postgres container** → Mitigation: split suites so unit tests
  stay container-free and fast; only integration tests require the database; CI provides it
  as a service container.
- **Two migration tools could later coexist (Doctrine Migrations vs. raw event-store DDL)**
  → Mitigation: standardize on Doctrine Migrations as the single mechanism; the event
  store's `events` table ships as a migration class under it, not a separate runner.

## Migration Plan

- This change adds tooling and an empty migration baseline; it introduces no domain schema
  itself (the first real migration — the `events` table — ships with
  `add-event-store-foundation`).
- Rollback: everything here is new project scaffolding; reverting removes the files. No data
  exists yet, so there is nothing to migrate or back up.

## Open Questions

- Exact migrations library/version pinning is finalized at implementation against the
  PHP 8.5 / Symfony 8 dependency matrix; the design assumes Doctrine Migrations as the
  mechanism but the requirements are written tool-agnostically (versioned, explicit,
  idempotent, reversible).
