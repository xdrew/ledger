## Context

ADR-006 fixed the policy: stored events are immutable at their written `schema_version`; the read
side transforms old payloads to the current shape at deserialization. The seam exists —
`EventSerializer::deserialize(type, schemaVersion, payload)` receives the stored version and the
registry knows each type's current version — so this change is the small mechanism plus the §6
example. The design questions are the chain's shape, failure mode, and wiring.

## Goals / Non-Goals

**Goals:** the `Upcaster`/`UpcasterChain` mechanism in the serializer; loud failure on gaps; DI
autowiring by tag; the `AccountOpened` v1→v2 example with tests proving old rows load.

**Non-Goals:** snapshots (orthogonal; noted in ADR-006 as the replay-cost valve); downcasting
(writers always emit current); offline stream migration tooling; changing any other event.

## Decisions

### D1: Single-step pure upcasters, chained by the serializer
`Upcaster` declares `eventType()` + `fromVersion()` + `upcast(array): array` — a pure payload
transformation for exactly one version step. `UpcasterChain` indexes them by `(type, fromVersion)`
and, given a stored version and the current version, applies steps sequentially. N versions need
N−1 upcasters; each is testable against a captured fixture of its input version. Registering two
upcasters for the same `(type, fromVersion)` throws at wiring time (ambiguity is a bug).

- *Alternatives rejected:* any-to-any upcasters (N² pairs, untestable surface); upcasting inside
  `fromPayload` (scatters version knowledge into event classes — rejected in ADR-006 as
  `??`-tolerance); a separate "upcasting projection" that rewrites rows (violates immutability).

### D2: Missing step fails loudly
If stored version < current version and no upcaster covers a step, `MissingUpcaster` is thrown
(kin to `UnknownEventType`). The alternative — passing the stale payload to `fromPayload` and
hoping defaults absorb it — fabricates state exactly where money data demands it least. A gap is a
deployment bug and must surface as one.

### D3: Hook placement and bypass
The chain runs inside `EventSerializer::deserialize`, before `fromPayload`, only when
`storedVersion < currentVersion`. Equal versions bypass the chain entirely (zero overhead for the
normal case). The serializer takes the chain as an optional constructor argument defaulting to an
empty chain, so existing direct constructions (tests, tooling) keep working unchanged.

### D4: DI mirrors the event-type-provider pattern
`_instanceof` tags every `Upcaster` implementation `app.upcaster`; `UpcasterChain` receives them as
a `!tagged_iterator`. Bounded contexts own their upcasters (`Accounts/Infrastructure/Upcasting/`),
parallel to how they own their `EventTypeProvider`.

### D5: The example — `AccountOpened` v1→v2
v2 adds `account_type` with derivable default `'standard'` (the ADR-006 rule for additive fields).
`AccountOpened` gains the property (constructor default keeps every existing call site compiling),
`toPayload` includes it, `AccountEventTypes` registers version 2, and `AccountOpenedV1ToV2` sets
the default on v1 payloads. The integration test inserts a **raw v1 row** (as
`DbalEventStoreTest` already does for other cases) and asserts both the loaded event shape and
aggregate rehydration.

## Risks / Trade-offs

- **Upcasting cost on hot old streams** → accepted per ADR-006; snapshots are the escape valve
  when it matters.
- **Fixture drift** (upcaster tested against a payload nobody stores anymore) → the fixture is a
  literal captured v1 payload in the test; it must never be "refreshed" to current shape.
- **Contributors adding a field without bumping the version** → the round-trip tests catch the
  common case; review + ADR-006 rules cover the rest.

## Open Questions

- None; scope is fixed by ADR-006 and §6.
