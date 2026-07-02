# ci Specification

## Purpose
TBD - created by archiving change add-ci. Update Purpose after archive.
## Requirements
### Requirement: Staged quality gate

The CI pipeline SHALL run on every push and pull request a fail-fast quality gate in this order:
OpenSpec strict validation (failing the build on invalid specs), composer manifest validation,
code-style lint, static analysis at max level, and the unit, integration, and functional test
suites (with a PostgreSQL service), plus Helm chart lint/template validation.

#### Scenario: Invalid specs fail the build early

- **WHEN** a change is pushed with an invalid OpenSpec change or spec
- **THEN** the pipeline fails at the spec gate before installing PHP dependencies

#### Scenario: The full test pyramid gates the build

- **WHEN** the quality gate runs
- **THEN** the unit, integration, and functional suites all execute and a failure in any fails the gate

### Requirement: Domain mutation testing with an MSI floor

The pipeline SHALL run mutation testing scoped to the domain layer after the quality gate and SHALL
fail when the mutation score indicator drops below the configured minimum.

#### Scenario: A gutted test suite is caught

- **WHEN** domain tests are weakened so that surviving mutants push the MSI below the floor
- **THEN** the mutation job fails the pipeline

### Requirement: Container build and publish

The pipeline SHALL build the production container image on every run, and on pushes to `main` SHALL
publish it to GHCR tagged with `latest` and the commit SHA.

#### Scenario: Pull requests prove the image builds

- **WHEN** a pull request runs
- **THEN** the production image builds (without being pushed)

#### Scenario: Main publishes to GHCR

- **WHEN** a commit lands on `main` and the quality gate passes
- **THEN** the image is pushed to GHCR with `latest` and SHA tags

### Requirement: CD smoke on a kind cluster

On `main`, the pipeline SHALL install the Helm chart on an ephemeral kind cluster using the image
built in the same run, wait for the deployments to become ready (probes passing, migrations applied
via the hook job), seed demo data, and execute one end-to-end transfer against the in-cluster API,
asserting it completes.

#### Scenario: The chart proves out on a real cluster

- **WHEN** the CD smoke job runs on `main`
- **THEN** both deployments reach ready, and a transfer POSTed to the in-cluster API returns status `completed`

