> Depends on `event-store` (global read) and the `accounts` event types. Read models are plain
> mutable tables (ADR-004). No new third-party dependencies.

## 1. Read-model schema

- [ ] 1.1 Migration creating `account_balances` (account_id PK, currency, available, reserved, total BIGINT, version INT, updated_at), `account_statement` (id, account_id, global_position, entry_type, amount, currency, occurred_at; index on (account_id, global_position); UNIQUE global_position), and `projection_checkpoints` (name PK, position BIGINT, updated_at). Reversible `down()`.

## 2. Projectors

- [ ] 2.1 Define a read-model write port and a `Projector` interface (`handles`, `project`).
- [ ] 2.2 Implement `AccountBalancesProjector` (DBAL): upsert on `AccountOpened`; apply deposit/hold/release/debit/credit deltas; set `version` from the event's stream version.
- [ ] 2.3 Implement `AccountStatementProjector` (DBAL): insert one ordered statement row per posting/hold event (idempotent via `UNIQUE(global_position)`).

## 3. Runner & checkpoint

- [ ] 3.1 Implement `CheckpointStore` (DBAL) reading/advancing the runner's last processed global position.
- [ ] 3.2 Implement `ProjectionRunner`: read the event store global stream from `checkpoint+1` in batches; per batch, in one transaction, apply each event to the projectors and advance the checkpoint atomically.
- [ ] 3.3 Implement projection-lag computation (latest global position − checkpoint).

## 4. Console & queries

- [ ] 4.1 Console commands `projections:rebuild` (truncate + reset checkpoint + replay), `projections:run` (catch up), `projections:status` (print lag).
- [ ] 4.2 Read query services `AccountBalanceView` and `AccountStatementView` over the read-model tables.
- [ ] 4.3 Wire DI (projectors, runner, checkpoint store, commands, query services).

## 5. Tests

- [ ] 5.1 Unit/integration: balances projector folds account events to correct available/reserved/total/version.
- [ ] 5.2 Integration: statement projector lists entries in global-position order.
- [ ] 5.3 Integration: `projections:rebuild` after a live projection yields identical `account_balances` (the "done" criterion).
- [ ] 5.4 Integration: re-running the caught-up runner changes nothing (exactly-once); lag is zero when caught up and equals the count of unprocessed events otherwise.

## 6. Verification & gate

- [ ] 6.1 Confirm the "done" criterion: drop + rebuild yields identical balances to the live projection.
- [ ] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration suites; `openspec validate add-projections --strict` passes.
