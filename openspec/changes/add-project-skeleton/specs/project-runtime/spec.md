## ADDED Requirements

### Requirement: Environment-based configuration

The application SHALL read its runtime configuration — at minimum the database DSN, the
application environment, and the log level — from environment variables, and SHALL NOT
embed credentials or environment-specific values in source. When a required configuration
value is absent, the application SHALL fail fast at startup with an explicit error rather
than continuing with an implicit default.

#### Scenario: Required configuration is read from the environment

- **WHEN** the application starts with the database DSN, environment, and log level set in
  the environment
- **THEN** it uses those values for its configuration

#### Scenario: Missing required configuration fails fast

- **WHEN** the application starts without a required configuration value (e.g. the database
  DSN)
- **THEN** startup aborts immediately with an explicit error identifying the missing value

#### Scenario: No secrets are committed in source

- **WHEN** the repository is inspected
- **THEN** configuration comes from the environment (with a non-secret `.env` example) and
  no real credentials are present in version-controlled source

### Requirement: Database connectivity

The application SHALL provide a Doctrine DBAL connection to PostgreSQL, configured from the
environment, as a dependency-injection service reused by runtime code and integration
tests. No ORM is used for this connection.

#### Scenario: A connection can execute a query against PostgreSQL

- **WHEN** the DBAL connection service is obtained and a trivial query is executed against a
  reachable PostgreSQL database
- **THEN** the query succeeds, confirming connectivity

#### Scenario: The connection uses the configured DSN

- **WHEN** the DBAL connection is created
- **THEN** it connects using the DSN from the environment configuration, not a hard-coded
  value

### Requirement: Versioned schema migrations

The application SHALL manage its database schema through versioned migrations applied by an
explicit command. Migrations SHALL NOT run automatically during application boot. Applying
migrations when none are pending SHALL make no changes, and an applied migration SHALL be
reversible.

#### Scenario: Applying pending migrations updates the schema

- **WHEN** the migration command is run with pending migrations
- **THEN** the pending migrations are applied and recorded as executed

#### Scenario: Re-running with nothing pending is a no-op

- **WHEN** the migration command is run and no migrations are pending
- **THEN** the schema is unchanged and the command reports nothing to do

#### Scenario: Migrations do not run on application boot

- **WHEN** the application boots normally (outside the migration command)
- **THEN** no migrations are executed as part of startup

#### Scenario: A migration can be rolled back

- **WHEN** the most recent migration is rolled back via the rollback command
- **THEN** its schema changes are reverted and it is no longer recorded as executed

### Requirement: Local development environment

The project SHALL provide a containerized local environment that starts PostgreSQL 18, so
the application and its integration tests can run against a real database without manual
setup.

#### Scenario: The local stack starts PostgreSQL 18

- **WHEN** the local environment is brought up via docker compose
- **THEN** a PostgreSQL 18 instance is running and accepting connections

#### Scenario: The application connects to the composed database

- **WHEN** the application or integration tests run against the composed environment using
  the environment configuration
- **THEN** they connect to the composed PostgreSQL instance successfully
