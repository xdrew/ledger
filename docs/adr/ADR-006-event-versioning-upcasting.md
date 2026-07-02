# ADR-006 — Event versioning and upcasting strategy

Status: Accepted (2026-07-02) · Schema versions persisted since `add-event-store-foundation`;
upcaster mechanism pending in `add-event-upcasting`.

## Context

Events are immutable and kept forever (ADR-001), but their shapes are not: fields get added,
renamed, split. A ledger cannot afford either of the naive answers — rewriting stored events
(destroys the audit property) or freezing event shapes forever (petrifies the domain). Every event
row already records a `schema_version` (`events.schema_version`, written by `EventSerializer` from
the `EventTypeRegistry`), so the storage side of versioning exists; the read-side transformation
policy is what this ADR fixes.

## Decision

**Upcast on read; never rewrite history.** Stored events are immutable at their written schema
version. When an event's current class shape advances (v1 → v2), a per-event-type **upcaster
chain** transforms the stored payload *at deserialization time* — inside
`EventSerializer::deserialize`, before `fromPayload` — stepping through versions one at a time
(v1→v2, v2→v3, …), so each upcaster is a small pure function `array → array` and N versions need
N-1 upcasters, not N² pairs. Writers always emit the newest version. Consumers (aggregate rehydration,
projectors, the relay) only ever see the current shape and stay version-unaware.

Rules:
- Additive change with a derivable default (add a field): bump `schema_version`, ship an upcaster
  supplying the default.
- Non-derivable or semantic change (meaning of a field changes): that is a **new event type**, not
  a new version — upcasters must never invent facts.
- Upcasters are pure, total for their input version, and unit-tested against captured v(n)
  payload fixtures.

**Honest status:** the mechanism (an `Upcaster` interface, registry wiring into
`EventSerializer::deserialize`, and one example — `AccountOpened` v1→v2 — with tests) is **not yet
implemented**. This ADR records the committed strategy; the follow-up change `add-event-upcasting`
implements it. Until then, `schema_version` is persisted and round-tripped but no transformation
occurs (all types are at v1).

## Consequences

- ✚ The audit log stays byte-stable forever; replays and rebuilds remain deterministic.
- ✚ Version awareness is confined to the serializer boundary — domain, projectors, and relay
  never branch on version.
- ✚ Chained single-step upcasters keep each migration reviewable and testable in isolation.
- ▬ Upcasting runs on every read of an old event: hot long streams pay repeatedly. Acceptable now;
  the escape valves are snapshots (which persist post-upcast state) and, in extremis, offline
  stream migration — a new stream written by replaying the old one, never in-place edits.
- ▬ Old payload fixtures must be kept so upcasters stay tested against reality.

## Options considered and rejected

- **In-place migration of stored events (`UPDATE events SET payload …`).** Destroys the audit
  guarantee the system exists to provide, breaks the append-only invariant, and races the relay
  and projections mid-migration. Rejected unconditionally.
- **Weak-schema tolerance ("just use `??` defaults in `fromPayload`").** Scatters version knowledge
  across every event class, silently absorbs genuinely malformed payloads, and offers no place to
  test v1→v2 explicitly. Acceptable for one optional field; an anti-pattern as policy.
- **Versioned event classes (`AccountOpenedV1`, `AccountOpenedV2` coexisting).** Pushes version
  branching into every consumer (`instanceof` ladders in projectors and aggregates) — the exact
  coupling upcast-on-read avoids.
- **Copy-transform the whole store on every schema change.** Deterministic but operationally heavy
  (dual-write during migration, cutover) for routine additive changes; reserved as the last resort
  noted above.
