## Why

Nothing in the codebase can be run or tested until there is a runnable project base: a
PHP/Symfony application that loads configuration, connects to PostgreSQL, applies database
migrations, and a way to spin all of that up locally. Building this "walking skeleton"
first means every subsequent change — starting with `add-event-store-foundation` — is
runnable and verifiable from day one, instead of accumulating unrunnable domain code until
the deployment/observability changes at the end. This is the minimal runtime obвязка
needed to *run and test* what we build; the full production wrapping (Helm, Grafana, OTel,
HPA, RoadRunner HTTP) deliberately stays in its dedicated later changes, where there is
something real to deploy and observe.

## What Changes

- Establish the **PHP 8.5 / Symfony 8** project: Composer manifest, PSR-4 autoload,
  `declare(strict_types=1)`, DI container, and the console application (no HTTP server /
  RoadRunner yet — that arrives with `add-http-api`).
- **Environment-based configuration**: database DSN, app environment, and log level read
  from the environment; no secrets in source; fail-fast on missing required config.
- **Database connectivity** via Doctrine DBAL to PostgreSQL 18, exposed as a DI service
  usable by runtime code and integration tests.
- **Versioned schema migrations** run by an explicit console command — never on app boot —
  idempotent when nothing is pending, with rollback support.
- **Local development environment**: a `docker compose` stack that starts PostgreSQL 18 so
  the app and its tests run against a real database.
- **Quality tooling as deliverables** (supporting the above, not separate spec
  capabilities): PHPUnit (unit + integration suites), PHPStan at max level, PHP-CS-Fixer
  (PSR-12), and a GitHub Actions workflow running the early/cheap gate — lint, static
  analysis, `openspec validate --strict`, and unit tests.

## Capabilities

### New Capabilities
- `project-runtime`: environment-driven configuration, a PostgreSQL/DBAL connection,
  explicit (never-on-boot) versioned migrations, and a local containerized environment —
  the minimal base that makes the project configurable, connectable, migratable, and
  runnable locally.

### Modified Capabilities
<!-- None — first change. The full `ci` capability spec is owned by the later `add-ci`
     change (which extends the GitHub Actions workflow introduced here as a deliverable);
     it is intentionally not specified here to keep the ci spec consolidated. -->

## Impact

- **New code (greenfield):** `composer.json`, Symfony kernel + DI config, console
  application, environment configuration loader, DBAL connection service/factory, the
  migrations setup (e.g. Doctrine Migrations) and its console command.
- **New infra files:** `docker-compose.yml` (PostgreSQL 18), `phpunit.xml` (unit +
  integration suites), `phpstan.neon` (max), `.php-cs-fixer.php`, `.github/workflows/ci.yml`
  (early gate), `.env` example.
- **Dependencies:** Symfony 8 (console, DI, dotenv), Doctrine DBAL, a migrations library;
  dev: PHPUnit, PHPStan, PHP-CS-Fixer. No ORM. No message bus, RoadRunner, or HTTP yet.
- **Downstream:** unblocks every later change by giving it a runnable, testable base;
  `add-event-store-foundation` drops its bootstrap tasks and depends on this skeleton.
  `add-ci` later extends the workflow with integration tests, mutation testing, container
  build, and CD smoke. `add-deployment` adds the production Dockerfile/Helm.
