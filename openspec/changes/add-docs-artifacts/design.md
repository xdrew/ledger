## Context

Documentation-only change producing the §8 artifacts. The decisions the ADRs record are already
made and embodied in code and the archived change designs — the work is to write them down in ADR
form with honest "options rejected" sections, and to write the three docs (`design`, `runbook`,
`slo`) that §8 specifies. Low design risk; the decisions worth noting are about form and guardrails.

## Goals / Non-Goals

**Goals:** the six ADRs, `docs/design.md` (with the four mandated analyses), `docs/runbook.md`
(four plays + alert index), `docs/slo.md` (tied to alerts), the README §9 items, and a test that
keeps them from rotting into stubs.

**Non-Goals:** implementing the upcaster (follow-up `add-event-upcasting`); the optional
`add-llm-statement-query`; restructuring existing docs/comments; generated API docs (OpenAPI already
serves that).

## Decisions

### D1: ADR format and status
Plain markdown, one file per ADR (`ADR-001-event-sourcing.md`, …), sections exactly as the brief
mandates: **Context / Decision / Consequences / Options considered and rejected**, plus a status
line (`Accepted`, with date). Consequences include the *negative* ones (e.g. ADR-001: replay cost,
schema evolution burden; ADR-003: stale reads) — an ADR that lists only upsides is marketing.

### D2: ADRs document reality, citing code
Each ADR cites the implementing code/change (e.g. ADR-002 → `OutboxRelay`, publish-then-checkpoint;
ADR-005 → `TransferOrchestrator` step order). Where the code already carries `ADR-00x` markers
(ADR-003/004/005 comments), the numbering stays consistent with them.

### D3: ADR-006 records strategy ahead of mechanism
§6 requires an upcaster mechanism + example; it is not built. ADR-006 fixes the *decision*
(upcast-on-read in `EventSerializer::deserialize`, chain per event type, never rewrite stored
events; `schema_version` already persisted) and names the follow-up change. The ADR is explicit
that the mechanism is pending — honesty over pretending.

### D4: Runbook keyed to alerts, commands copy-pastable
Each play: symptom → diagnosis (exact commands: `projections:status`, SQL on checkpoints, `kubectl`)
→ action → verification. The alert index maps every rule in `deploy/observability/alerts.yaml` to
its play. Commands are the real ones from the Taskfile/console so they can be pasted verbatim.

### D5: SLOs derive from the alert thresholds already shipped
`docs/slo.md` states each SLO with its measurement (PromQL from the dashboard/alerts), window, and
the alert that burns it: API p99 ≤ 500ms (matches `RequestLatencyP99SloBurn`), projection lag ≤ 30s
(matches `ProjectionLagHigh`), outbox drain, availability. Where a target is aspirational for a demo
system, it says so.

### D6: Anti-stub test
`DocsArtifactsTest` (unit suite) asserts every artifact exists and contains its mandated headings
(each ADR: the four sections; design.md: brownfield + 100x + cost sections; runbook: the four plays;
slo.md: the SLO table and alert mapping). Same pattern as `MonitoringAssetsTest` — cheap, keeps the
"present and substantive" claim enforceable in CI.

## Risks / Trade-offs

- **Docs drift from code** → Mitigation: ADRs cite specific classes; the anti-stub test catches
  deletion/gutting; runbook commands are the Taskfile/console ones exercised by CI.
- **A sections-presence test can't judge substance** → Accepted: it guards structure; substance is
  reviewed here.

## Open Questions

- None blocking; the ADR content is settled by the existing code and archived designs.
