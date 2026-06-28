> Depends on `event-store` (global read; the events table is the outbox). No new third-party
> dependencies. Migrations are generated from the schema configurator.

## 1. Schema

- [ ] 1.1 In `LedgerSchemaProvider`: rename `projection_checkpoints` → `stream_checkpoints` (shared) and add `consumed_events` (consumer VARCHAR, event_id UUID, PK (consumer, event_id)). Generate the migration via `doctrine:migrations:diff`.
- [ ] 1.2 Point the projections `CheckpointStore` at `stream_checkpoints` (rename only).

## 2. Transport port

- [ ] 2.1 Define the `EventPublisher` port (`publish(RecordedEvent): void`).
- [ ] 2.2 Implement `InMemoryEventPublisher` (records published events for tests).
- [ ] 2.3 Implement `PostgresNotifyEventPublisher` (`pg_notify` with event id + global position payload).

## 3. Relay & idempotency

- [ ] 3.1 Implement the relay checkpoint (over `stream_checkpoints`, name `outbox`).
- [ ] 3.2 Implement `OutboxRelay`: read events from the checkpoint in global order; for each, publish then advance the checkpoint (at-least-once, no loss); gap-aware cursor with a settle window.
- [ ] 3.3 Implement the `ConsumedEvents` idempotency guard (record/skip by consumer + event id).

## 4. Console & wiring

- [ ] 4.1 Add the `outbox:relay` console command (`--once` pass; loop with sleep otherwise).
- [ ] 4.2 Wire DI: bind `EventPublisher` to the Postgres-notify adapter by default; register the relay, checkpoint, and guard.

## 5. Tests

- [ ] 5.1 Integration: the relay publishes every recorded event in ascending global-position order; checkpoint ends at the latest position.
- [ ] 5.2 Integration: a publish failure does not advance the checkpoint; the next run re-attempts (at-least-once).
- [ ] 5.3 Integration: kill mid-batch (stop after N) then restart → remaining events published, none lost.
- [ ] 5.4 Integration/unit: an idempotent consumer applies a re-delivered event's effect exactly once.

## 6. Verification & gate

- [ ] 6.1 Confirm the "done" criterion: killing the relay mid-batch and restarting loses no events and double-delivers none observably.
- [ ] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration suites; `openspec validate add-outbox --strict` passes.
