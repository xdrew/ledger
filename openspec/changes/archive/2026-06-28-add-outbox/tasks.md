> Depends on `event-store` (global read; the events table is the outbox). No new third-party
> dependencies. Migrations are generated from the schema configurator.

## 1. Schema

- [x] 1.1 In `LedgerSchemaProvider`: add `consumed_events` (consumer VARCHAR, event_id UUID, PK (consumer, event_id)). Generate the migration via `doctrine:migrations:diff`.
- [x] 1.2 Reuse the existing `projection_checkpoints` table for the relay cursor (row named `outbox`) — no rename, no destructive migration.

## 2. Transport port

- [x] 2.1 Define the `EventPublisher` port (`publish(RecordedEvent): void`).
- [x] 2.2 Implement `InMemoryEventPublisher` (records published events for tests).
- [x] 2.3 Implement `PostgresNotifyEventPublisher` (`pg_notify` with event id + global position payload).

## 3. Relay & idempotency

- [x] 3.1 Implement the relay checkpoint (`RelayCheckpoint` over `projection_checkpoints`, name `outbox`).
- [x] 3.2 Implement `OutboxRelay`: read events from the checkpoint in global order; for each, publish then advance the checkpoint (at-least-once, no loss). Gap hardening documented as a deferred scale path (design D5).
- [x] 3.3 Implement the `ConsumedEvents` idempotency guard (record/skip by consumer + event id).

## 4. Console & wiring

- [x] 4.1 Add the `outbox:relay` console command (one catch-up pass).
- [x] 4.2 Wire DI: bind `EventPublisher` to the Postgres-notify adapter by default; relay/checkpoint/guard autowired.

## 5. Tests

- [x] 5.1 Integration: the relay publishes every recorded event in ascending global-position order; checkpoint ends at the latest position.
- [x] 5.2 Integration: a publish failure does not advance the checkpoint; the next run re-attempts (at-least-once, no loss).
- [x] 5.3 Integration: kill mid-batch (stop after N) then restart → remaining events published, none lost.
- [x] 5.4 Integration: an idempotent consumer applies a re-delivered event's effect exactly once.

## 6. Verification & gate

- [x] 6.1 Confirmed the "done" criterion: killing the relay mid-batch and restarting loses no events and double-delivers none observably (idempotent consumer).
- [x] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit (73) + integration (25); `openspec validate add-outbox --strict` passes.
