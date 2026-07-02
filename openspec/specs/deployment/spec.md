# deployment Specification

## Purpose
TBD - created by archiving change add-deployment. Update Purpose after archive.
## Requirements
### Requirement: Production image runs api or worker

The system SHALL provide a multi-stage, non-root production image that bundles PHP, the RoadRunner
binary, and the application, and runs either the **api** (HTTP server) or the **worker** (outbox
relay + projections) selected by the launch command.

#### Scenario: The image serves the API

- **WHEN** the image is run with the `api` command
- **THEN** RoadRunner serves the HTTP API and `GET /healthz` returns `200`

#### Scenario: The same image runs the worker

- **WHEN** the image is run with the `worker` command
- **THEN** it runs the outbox relay and projection catch-up on a loop, with no HTTP server

### Requirement: Continuous worker with clean shutdown

The worker SHALL run the outbox relay and projection catch-up continuously, and SHALL stop cleanly
on `SIGTERM` between iterations without leaving a half-processed batch.

#### Scenario: Worker drains on shutdown

- **WHEN** the worker receives `SIGTERM` while looping
- **THEN** it finishes the current iteration and exits without partial processing

### Requirement: Migrations run as a job, not on boot

Database migrations SHALL run as an explicit step (a compose one-shot and a Helm pre-install/
pre-upgrade job), and the api and worker SHALL NOT run migrations on startup.

#### Scenario: App start does not migrate

- **WHEN** an api or worker container starts
- **THEN** it does not apply migrations; migrations are applied only by the migrate job/step

### Requirement: Local stack is migrated, seeded, and observable

`docker compose up` SHALL bring up PostgreSQL, the api, the worker, Prometheus, Grafana, and an
OpenTelemetry collector, after running migrations and a seed step, yielding a working, populated
system with metrics scraped.

#### Scenario: One command yields a populated system

- **WHEN** `docker compose up` completes
- **THEN** migrations and the seed have run, the api answers `/healthz`, and Prometheus scrapes the api and worker metrics

### Requirement: Seed command creates demo data

The system SHALL provide a seed command that creates demo accounts and transfers through the command
bus and catches the read models up, so a fresh environment has representative data.

#### Scenario: Seeding creates accounts and transfers

- **WHEN** the seed command runs against a migrated database
- **THEN** demo accounts and transfers exist and the read models reflect them

### Requirement: Helm chart deploys api and worker

The system SHALL provide a Helm chart with separate api and worker Deployments, a Service, config and
secret, liveness/readiness/startup probes wired to `/healthz` and `/readyz`, autoscaling, a
pre-upgrade migration job, Prometheus scrape configuration, a PodDisruptionBudget, and graceful
shutdown.

#### Scenario: The chart renders the required resources

- **WHEN** the chart is rendered with `helm template`
- **THEN** it produces separate api and worker Deployments, the pre-install/pre-upgrade migration Job, the probes, an HPA, a PodDisruptionBudget, and scrape configuration

#### Scenario: The chart is valid

- **WHEN** `helm lint` runs against the chart
- **THEN** it reports no errors

