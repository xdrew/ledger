> Depends on `event-store` (global read) and the `accounts` event types. Read models are plain
> mutable tables (ADR-004). No new third-party dependencies.

## 1. Read-model schema

- [x] 1.1 Add the read-model tables to the `LedgerSchemaProvider` schema configurator (`account_balances`: account_id PK, currency, available/reserved/total BIGINT, version, updated_at; `account_statement`: id, account_id, global_position, entry_type, amount, currency, occurred_at; index on (account_id, global_position); UNIQUE global_position; `projection_checkpoints`: name PK, position, updated_at) and **generate** the migration via `doctrine:migrations:diff` (not hand-authored).

## 2. Projectors

- [x] 2.1 Define a `Projector` interface (`handles`, `project`).
- [x] 2.2 Implement `AccountBalancesProjector` (DBAL): upsert on `AccountOpened`; apply deposit/hold/release/debit/credit deltas; set `version` from the event's stream version.
- [x] 2.3 Implement `AccountStatementProjector` (DBAL): insert one ordered statement row per posting/hold event (idempotent via `UNIQUE(global_position)`).

## 3. Runner & checkpoint

- [x] 3.1 Implement `CheckpointStore` (DBAL) reading/advancing the runner's last processed global position.
- [x] 3.2 Implement `ProjectionRunner`: read the event store global stream from `checkpoint+1` in batches; per batch, in one transaction, apply each event to the projectors and advance the checkpoint atomically.
- [x] 3.3 Implement projection-lag computation (latest global position − checkpoint).

## 4. Console & queries

- [x] 4.1 Console commands `projections:rebuild` (truncate + reset checkpoint + replay), `projections:run` (catch up), `projections:status` (print checkpoint/head/lag).
- [x] 4.2 Read query services `AccountBalanceView` and `AccountStatementView` over the read-model tables.
- [x] 4.3 Wire DI (projectors via tagged `app.projector` + runner tagged_iterator, checkpoint store, commands, query services).

## 5. Tests

- [x] 5.1 Integration: balances projector folds account events to correct available/reserved/total/version.
- [x] 5.2 Integration: statement projector lists entries in global-position order.
- [x] 5.3 Integration: `projections:rebuild` (via the runner) after a live projection yields identical `account_balances` (the "done" criterion).
- [x] 5.4 Integration: re-running the caught-up runner changes nothing (exactly-once); lag is zero when caught up and equals the count of unprocessed events otherwise.

## 6. Verification & gate

- [x] 6.1 Confirmed: drop + rebuild yields identical balances to the live projection.
- [x] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit (73) + integration (21); `openspec validate add-projections --strict` passes.
