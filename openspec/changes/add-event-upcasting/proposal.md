## Why

Brief §6 requires an **upcaster mechanism plus at least one example upcaster** — the one functional
requirement still open. ADR-006 fixed the strategy (upcast-on-read at deserialization, never rewrite
history) and explicitly deferred the mechanism to this change. Events already persist a
`schema_version` and the registry tracks each class's current version; what is missing is the
transformation step between them.

## What Changes

- **Upcaster mechanism** in the event store's serialization layer:
  - An `Upcaster` interface: `eventType(): string`, `fromVersion(): int`,
    `upcast(array $payload): array` — one pure single-step transformation (vN → vN+1).
  - An `UpcasterChain` that indexes upcasters by `(type, fromVersion)` and steps a stored payload
    from its written version to the class's current version one version at a time. A **missing
    step fails loudly** (`MissingUpcaster`) — silently feeding a stale payload to `fromPayload`
    would fabricate state.
  - `EventSerializer::deserialize` runs the chain before `fromPayload` when the stored
    `schema_version` is below the registry's current version for the type. Writers always emit the
    current version (unchanged behaviour). Consumers stay version-unaware.
  - DI: upcasters are autoconfigured via a tag (`app.upcaster`, mirroring the
    `app.event_type_provider` pattern) and collected into the chain.
- **Example upcaster (the §6 requirement):** `AccountOpened` goes **v1 → v2**, adding an
  `account_type` field with the derivable default `standard`:
  - `AccountOpened` gains `public readonly string $accountType` (default `'standard'`), included in
    `toPayload`; `AccountEventTypes` registers the type at schema version **2**.
  - `AccountOpenedV1ToV2` (Accounts infrastructure) supplies the default for stored v1 payloads.
  - Existing v1 rows in any database load unchanged through the chain — verified by an integration
    test that inserts a raw v1 row and loads it.
- **ADR-006 status updated**: "mechanism pending" → implemented by this change (the strategy text
  is already accurate).

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `event-store`: adds the upcast-on-read requirement — stored events at older schema versions are
  transformed to the current shape at deserialization via registered single-step upcasters; a
  missing step is a loud failure; stored payloads are never rewritten.
- `accounts`: `AccountOpened` is at schema version 2 (`account_type`, default `standard`); v1
  events remain loadable forever via the upcaster.

## Impact

- **New code:** `src/EventStore/Serialization/{Upcaster,UpcasterChain,MissingUpcaster}.php`;
  `src/Accounts/Infrastructure/Upcasting/AccountOpenedV1ToV2.php`. **Changed:** `EventSerializer`
  (chain hook), `AccountOpened` (+field), `AccountEventTypes` (version 2), `Account::open`
  (passes the default), `config/services.yaml` (tag + chain wiring), `docs/adr/ADR-006` (status).
- **Tests:** unit — chain steps v1→v2 from a captured v1 fixture, multi-step composition, missing
  step throws, current-version payloads bypass the chain; integration — a raw v1 row in PostgreSQL
  loads as the v2 shape and the account rehydrates.
- **No schema change, no data migration** — that is the point of the decision.
- **Compatibility:** all existing events were written as v1 with the same payload shape; new writes
  are v2. In-memory store and all consumers are untouched (they see only current shapes).
