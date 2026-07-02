## Why

Every runtime capability is built, but the brief's differentiator — §8, the principal-level
artifacts — does not exist yet: no ADRs, no design doc, no runbook, no SLO document, and the README
still has placeholder sections ("How to rebuild a projection — not applicable yet"). These artifacts
record *why* the system is shaped the way it is, how to operate it, and what its limits are; §9
makes them part of the definition of done ("present and substantive, not stubs").

## What Changes

- **ADRs** under `docs/adr/`, each with *Context / Decision / Consequences / Options considered and
  rejected*, documenting decisions already embodied in the code and the archived change designs:
  - **ADR-001** — Event Sourcing for the ledger (rejected: CRUD + audit table, CDC-derived audit).
  - **ADR-002** — Transactional outbox (the event log *is* the outbox, checkpoint relay) vs
    dual-write vs CDC for publication.
  - **ADR-003** — Async projections vs synchronous read-after-write; the eventual-consistency
    trade-off and how the API exposes it (`version` on reads).
  - **ADR-004** — Where we deliberately avoid ES: the idempotency store and read models as plain
    mutable tables; over-applying ES is a cost, not a virtue.
  - **ADR-005** — Saga orchestration vs choreography for transfers (hold → post → settle with
    compensation = release hold).
  - **ADR-006** — Event versioning / upcasting strategy: events carry `schema_version` today;
    the ADR fixes the strategy (upcast-on-read at deserialization, never rewrite history) and its
    trigger conditions. *Note:* the upcaster mechanism itself is not yet implemented (§6 requires
    one plus an example); this ADR records the decision, and a small follow-up change
    (`add-event-upcasting`) implements it.
- **`docs/design.md`** — the written design doc that anticipates objections: architecture summary
  with diagram, an **options considered and rejected** section, the **brownfield evolution path**
  (migrating a legacy mutable-balance system with millions of postings via dual-write, backfill,
  parity-check, cutover, rollback — no downtime), the **100x scaling analysis** (what breaks first:
  single-sequence event-store writes / projection lag / relay fan-out — and mitigations:
  partitioning, parallel projectors by stream, snapshotting, NATS), and a short **cost / on-call**
  note.
- **`docs/runbook.md`** — operational playbook keyed to the alert rules: rebuild a projection
  (`projections:rebuild`, lag expectations), drain a stuck transfer saga (diagnose by status,
  release-hold compensation, when to fail manually), handle outbox backlog (relay down vs slow
  transport; checkpoint semantics; safe restart), and what each Prometheus alert means + first
  response (`ProjectionLagHigh`, `OutboxBacklogGrowing`, `RequestLatencyP99SloBurn`, `NotReady`).
- **`docs/slo.md`** — SLOs tied to the shipped alert rules and dashboard: transfer/API p99 latency,
  projection lag, availability, plus measurement windows and the alert↔SLO mapping.
- **README completion** (§9): mermaid architecture diagram, bounded-context map, updated "how to
  rebuild a projection", and the honest "what is deliberately NOT built" list refreshed to the
  final state.
- **Anti-stub guard:** a small unit test (like the monitoring-assets test) asserting the documents
  exist and contain their required sections, so they cannot silently rot or be gutted.

## Capabilities

### New Capabilities
- `docs`: the principal-level documentation set — six ADRs (with options-rejected sections), the
  design doc (objections, brownfield path, 100x analysis, cost note), the operational runbook keyed
  to the alerts, and the SLO document tied to the alert rules — guarded by a presence/sections test.

### Modified Capabilities
<!-- None. Documentation only; no runtime behaviour changes. -->

## Impact

- **New files:** `docs/adr/ADR-001…006.md`, `docs/design.md`, `docs/runbook.md`, `docs/slo.md`;
  `tests/Unit/Docs/DocsArtifactsTest.php`. **Changed:** `README.md`.
- **No runtime code changes.** The one intentional gap this surfaces: §6's upcaster mechanism +
  example upcaster is still unimplemented — ADR-006 records the strategy; implementation goes to a
  follow-up `add-event-upcasting` change (kept separate per the one-capability-per-change rule).
- Content sources: the archived change designs (`openspec/changes/archive/*`), the ADR references
  already scattered in code comments (ADR-003/004/005), and the shipped alerts/dashboard.
