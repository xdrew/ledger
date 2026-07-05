## 1. PHP / Symfony project bootstrap

- [x] 1.1 Create `composer.json` targeting PHP 8.5 with PSR-4 autoload for `src/` and `tests/`; require Symfony 8 (console, framework-bundle, dotenv) and Doctrine DBAL.
- [x] 1.2 Stand up the Symfony kernel + DI container and a console application entrypoint (`bin/console`), console-only (no HTTP / RoadRunner).
- [x] 1.3 Add an `.env` example and the Symfony Dotenv wiring; document required variables (DB DSN, app env, log level).

## 2. Configuration & database connectivity

- [x] 2.1 Implement environment-based configuration loading for DB DSN, app environment, and log level, with fail-fast validation of required values.
- [x] 2.2 Configure the Doctrine DBAL `Connection` via `doctrine-bundle` ^3 from the env DSN (no ORM, `doctrine/orm` not installed); autowirable into runtime services and tests.
- [x] 2.3 Add a smoke check (`app:db:ping` console command + integration test) that obtains the connection and runs a trivial query.

## 3. Migrations

- [x] 3.1 Add the migrations mechanism via `doctrine-migrations-bundle` ^4 (supports Symfony 8), configured with `migrations_paths` pointing at `migrations/`.
- [x] 3.2 Use the bundle's `doctrine:migrations:*` console commands (`migrate`, `up-to-date`, `sync-metadata-storage`, …); confirm they run only on explicit invocation, never on boot.
- [x] 3.3 Establish an empty migrations baseline (no domain schema yet). Note (found by running): `doctrine:migrations:migrate` errors on zero registered migrations, so the mechanism is verified via `sync-metadata-storage` + `up-to-date`; concrete apply + rollback lands with the first real migration in `add-event-store-foundation`.

## 4. Local environment (docker compose)

- [x] 4.1 Write `docker-compose.yml` starting PostgreSQL 18 (mount at `/var/lib/postgresql` per PG18 image) with healthcheck, named volume, and a dev app image (`docker/php/Dockerfile`).
- [x] 4.2 Wire the app/tests to connect to the composed database via env configuration; create `ledger_test` via an initdb script; document `docker compose up` / `task` (Taskfile) in the README.

## 5. Test & quality tooling

- [x] 5.1 Configure PHPUnit with separate `unit` (container-free) and `integration` (requires Postgres) suites; pin `APP_ENV=test` in the bootstrap so `KernelTestCase` boots the test container.
- [x] 5.2 Install PHPStan (max), PHP-CS-Fixer (PSR-12), and PHPUnit in isolated `vendor-bin/<tool>` namespaces via `bamarni/composer-bin-plugin` (bin-links + forward-command); point PHPStan at `vendor-bin/phpunit/vendor` so it can resolve `TestCase`. Add `composer` scripts: `lint`, `analyse`, `test:unit`, `test:integration`.
- [x] 5.3 Add integration tests: DB connectivity via the wired connection, and the migrations mechanism (metadata sync + up-to-date).

## 6. Early CI gate (deliverable)

- [x] 6.1 Add `.github/workflows/ci.yml` running, on push and PR: `openspec validate --all --strict` → composer validate → lint → PHPStan max → unit tests, each gating the next; cache Composer.
- [x] 6.2 Add a Postgres 18 service container to CI and run the integration suite after unit tests. (Full pipeline — mutation, container build, CD smoke — is deferred to `add-ci`.)

## 7. Verification & gate

- [x] 7.1 Confirmed: `docker compose up` yields a healthy PostgreSQL 18, `app:db:ping` succeeds via env config, and the migrations mechanism (`sync-metadata-storage` + `up-to-date`) round-trips on the empty baseline.
- [x] 7.2 Confirmed green: php-cs-fixer (0 issues), PHPStan max (no errors), unit (1 test) + integration (2 tests) suites; `openspec validate add-project-skeleton --strict` passes.
