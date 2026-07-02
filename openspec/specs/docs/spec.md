# docs Specification

## Purpose
TBD - created by archiving change add-docs-artifacts. Update Purpose after archive.
## Requirements
### Requirement: Architecture decision records

The repository SHALL contain ADRs 001–006 under `docs/adr/` — Event Sourcing, transactional outbox,
async projections, deliberate non-ES, saga orchestration, and event versioning/upcasting — each with
Context, Decision, Consequences (including negative ones), and an Options-considered-and-rejected
section, citing the implementing code.

#### Scenario: ADRs are present and structured

- **WHEN** the ADR files are inspected
- **THEN** all six exist and each contains the Context, Decision, Consequences, and Options-considered-and-rejected sections

### Requirement: Design document with mandated analyses

The repository SHALL contain `docs/design.md` covering: an architecture summary, options considered
and rejected, a brownfield evolution path (dual-write migration from a legacy mutable-balance system
with rollback and no downtime), a 100x scaling analysis naming what breaks first and mitigations,
and a cost / on-call note.

#### Scenario: The design doc covers the required analyses

- **WHEN** `docs/design.md` is inspected
- **THEN** it contains the options-rejected, brownfield-evolution, 100x-scaling, and cost/on-call sections

### Requirement: Operational runbook keyed to alerts

The repository SHALL contain `docs/runbook.md` with executable plays for rebuilding a projection,
draining a stuck transfer saga, and handling outbox backlog, plus an index mapping every shipped
Prometheus alert rule to its meaning and first response.

#### Scenario: Every alert has a documented response

- **WHEN** the alert rules in `deploy/observability/alerts.yaml` are compared with the runbook
- **THEN** each alert name appears in the runbook with a first-response action

### Requirement: SLOs tied to alert rules

The repository SHALL contain `docs/slo.md` defining service-level objectives (including API latency,
projection lag, and availability) with measurement queries and windows, each tied to the alert rule
that signals its burn.

#### Scenario: SLOs map to alerts

- **WHEN** `docs/slo.md` is inspected
- **THEN** each SLO names its measurement and the corresponding alert rule

### Requirement: README completeness

The README SHALL include an architecture diagram, a bounded-context map, instructions to run the
system and rebuild a projection, and an honest list of what is deliberately not built.

#### Scenario: The README covers the definition-of-done items

- **WHEN** the README is inspected
- **THEN** it contains the diagram, context map, run and rebuild-a-projection instructions, and the not-built list

### Requirement: Documentation guarded against rot

A test SHALL assert that the documentation artifacts exist and contain their mandated sections, so
CI fails if they are removed or gutted.

#### Scenario: Gutted docs fail the build

- **WHEN** a mandated section is removed from an ADR or doc
- **THEN** the docs guard test fails

