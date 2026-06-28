> Depends on `add-project-skeleton` (PHP 8.5 / Symfony 8 project, DBAL connection,
> migrations mechanism, docker compose Postgres 18, PHPUnit/PHPStan/CS-Fixer, CI gate).
> This change adds no project scaffolding — it builds on that base.

## 1. Shared kernel primitives

- [x] 1.1 Define the `Clock` port and `SystemClock` adapter; `FixedClock` test double lives in `tests/Support`.
- [x] 1.2 Define `EventId` (UUIDv7 via symfony/uid) and the `StreamId` value object (stream type + id).
- [x] 1.3 Define the `DomainEvent` interface and the `RecordedEvent` envelope (event id, stream id, version, type, schema version, deserialized event, occurred-at, `EventMetadata` correlation/causation, global position).

## 2. Event serialization

- [x] 2.1 Implement `EventTypeRegistry` mapping stable type strings ↔ event classes (+ per-class schema version).
- [x] 2.2 Implement `EventSerializer` (event → type/schema/payload) and deserialize (→ typed `DomainEvent`), throwing `UnknownEventType` on unregistered types.
- [x] 2.3 Reserve and persist the integer `schema_version` per event (no upcasting yet; deserialize ignores it for now).

## 3. Event store port & in-memory adapter

- [x] 3.1 Define the `EventStore` port: `append(streamId, expectedVersion, events, metadata?)`, `load(streamId)`, `readFrom(afterPosition, limit)`; define `ConcurrencyConflict`.
- [x] 3.2 Implement `InMemoryEventStore` honoring per-stream contiguous versioning, optimistic-concurrency rejection (atomic), and store-wide global ordering.
- [x] 3.3 Unit-test the in-memory adapter against the full contract (append/load order, contiguous versioning, stale-version rejection without persistence, global ordering, readFrom cursor, unknown-type rejection).

## 4. PostgreSQL (DBAL) adapter

- [x] 4.1 Migration `Version20260101000000` creating the `events` table: `global_position BIGINT GENERATED ALWAYS AS IDENTITY` PK, `event_id` UUID (unique), `stream_type`, `stream_id`, `version`, `event_type`, `schema_version`, `payload JSONB`, `metadata JSONB`, `occurred_at`/`recorded_at TIMESTAMPTZ`, `UNIQUE (stream_type, stream_id, version)`. (`global_position` PK covers global-ordered reads.) Reversible `down()`.
- [x] 4.2 Implement `DbalEventStore.append` in a single transaction (version pre-check + insert); translate the unique-violation (`UniqueConstraintViolationException`) into `ConcurrencyConflict`, letting other errors propagate; nothing persists on conflict.
- [x] 4.3 Implement `DbalEventStore.load` (ascending version) and `readFrom` (global-ordered read from a cursor) with JSONB (de)serialization and metadata hydration.
- [x] 4.4 Integration-test the DBAL adapter against PostgreSQL: append/load round-trip (incl. occurred-at from clock + correlation/causation), contiguous versioning, atomic concurrency rejection (nothing persisted), global ordering, readFrom cursor, and the unique-constraint guard.

## 5. Base aggregate root

- [x] 5.1 Implement `AggregateRoot`: `recordThat()` (apply + stage), abstract `apply()` mutator, version tracking, `pullUncommittedEvents()` (return + clear), `reconstituteFromHistory()` (replay without re-recording, advancing version).
- [x] 5.2 Unit-test the aggregate root via the `Counter` test aggregate: recording stages + mutates state, pulling clears, reconstitution rebuilds state and version with an empty uncommitted list.

## 6. Verification & gate

- [x] 6.1 "Done" criteria confirmed: a stream can be appended and loaded, and an append with a stale version is rejected with nothing persisted (in-memory + DBAL adapter tests).
- [x] 6.2 Green: php-cs-fixer (0 issues), PHPStan max (no errors), unit (14 tests) + integration (8 tests); `openspec validate add-event-store-foundation --strict` passes.
