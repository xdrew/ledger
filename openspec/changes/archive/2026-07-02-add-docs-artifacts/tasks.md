> Documentation only; no runtime changes. Content sources: archived change designs, ADR markers in
> code, the shipped alerts/dashboard. The §6 upcaster implementation is explicitly deferred to a
> follow-up `add-event-upcasting` change (ADR-006 records the strategy).

## 1. ADRs (`docs/adr/`)

- [x] 1.1 ADR-001 — Event Sourcing for the ledger (rejected: CRUD + audit table, CDC-derived audit). Consequences include replay/evolution costs.
- [x] 1.2 ADR-002 — Transactional outbox: the event log as outbox + checkpointed relay (publish-then-checkpoint, at-least-once) vs dual-write vs CDC.
- [x] 1.3 ADR-003 — Async projections vs synchronous read-after-write; consistency exposed via `version` on reads.
- [x] 1.4 ADR-004 — Deliberate non-ES: idempotency store + read models as plain tables; over-applying ES is a cost.
- [x] 1.5 ADR-005 — Saga orchestration vs choreography; hold → post → settle order and release-hold compensation.
- [x] 1.6 ADR-006 — Event versioning / upcasting: upcast-on-read at deserialization, never rewrite history; mechanism deferred to `add-event-upcasting` (stated explicitly).

## 2. Design doc (`docs/design.md`)

- [x] 2.1 Architecture summary + diagram; options considered and rejected (cross-referencing the ADRs).
- [x] 2.2 Brownfield evolution path: legacy mutable-balance system → dual-write, backfill, parity check, cutover, rollback; no downtime.
- [x] 2.3 100x scaling analysis: what breaks first (event-store write throughput / projection lag / relay), in what order, with mitigations (partitioning, parallel projectors, snapshots, NATS).
- [x] 2.4 Cost / on-call note.

## 3. Runbook (`docs/runbook.md`)

- [x] 3.1 Play: rebuild a projection (commands, expected lag behaviour, verification).
- [x] 3.2 Play: drain a stuck transfer saga (diagnose by status, compensation, manual failure).
- [x] 3.3 Play: outbox backlog (relay down vs slow transport, checkpoint semantics, safe restart).
- [x] 3.4 Alert index: every rule in `deploy/observability/alerts.yaml` → meaning + first response.

## 4. SLOs (`docs/slo.md`)

- [x] 4.1 SLOs with measurement (PromQL), window, and target: API p99 latency, projection lag, availability, outbox drain; each mapped to its alert rule.

## 5. README (§9 completion)

- [x] 5.1 Mermaid architecture diagram + bounded-context map.
- [x] 5.2 Replace the stale "How to rebuild a projection" placeholder with the real procedure; refresh the "deliberately NOT built" list to final state.

## 6. Guard & gate

- [x] 6.1 `DocsArtifactsTest` (unit): every artifact exists with its mandated sections.
- [x] 6.2 Green: php-cs-fixer, PHPStan max, all suites; `openspec validate add-docs-artifacts --strict` passes.
