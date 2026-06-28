> Depends on `project-runtime` (DBAL connection, migrations). Independent of the event store —
> this is a plain mutable table by design (ADR-004). No new third-party dependencies.

## 1. Values & outcomes

- [x] 1.1 Implement `IdempotencyKey` (non-empty string VO) and `StoredResponse` (int status, header map, string body).
- [x] 1.2 Implement the `begin` outcome types: `Begun`, `InProgress`, `Mismatch`, `Completed(StoredResponse)` (a `BeginOutcome` marker hierarchy for exhaustive branching).
- [x] 1.3 Implement the `Ttl` configuration value (positive seconds, `expiresFrom()`).

## 2. Store port & in-memory double

- [x] 2.1 Define the `IdempotencyStore` port: `begin(key, route, requestHash): BeginOutcome` and `complete(key, route, StoredResponse): void`.
- [x] 2.2 Implement `InMemoryIdempotencyStore` honouring the classification rules (begin/replay/in-progress/mismatch/TTL reclaim).
- [x] 2.3 Unit-test the in-memory store: fresh begin; replay completed; in-progress conflict; payload mismatch; TTL reclaim.

## 3. PostgreSQL (DBAL) adapter

- [x] 3.1 Migration `Version20260102000000` creating `idempotency_keys` (key, route, request_hash, status, response_status, response_headers JSONB, response_body, created_at, completed_at, expires_at; PK `(idempotency_key, route)`; index on `expires_at`). Reversible `down()`.
- [x] 3.2 Implement `DbalIdempotencyStore.begin` via `INSERT … ON CONFLICT (idempotency_key, route) DO NOTHING`; on conflict, reclaim expired rows atomically then classify (mismatch / in-progress / completed), with bounded retry on a vanished row.
- [x] 3.3 Implement `DbalIdempotencyStore.complete` (store response, mark completed, `expires_at = now() + ttl`).

## 4. Wiring

- [x] 4.1 Bind `IdempotencyStore` → `DbalIdempotencyStore`; build `Ttl` from `IDEMPOTENCY_TTL_SECONDS` (env, default 86400).

## 5. Tests

- [x] 5.1 Integration: begin → complete → replay round-trip against Postgres (stored response returned).
- [x] 5.2 Integration: in-progress conflict and payload-mismatch outcomes against Postgres.
- [x] 5.3 Integration (concurrency): two `begin`s for the same key on **separate connections** yield exactly one Begun; the other is InProgress — exactly one state change.
- [x] 5.4 Integration: TTL reclaim — a completed-but-expired key is begun anew.

## 6. Verification & gate

- [x] 6.1 "Done" criterion confirmed: concurrent duplicate begins produce exactly one state change.
- [x] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit (60) + integration (15); `openspec validate add-idempotency --strict` passes. (All tests use `#[Test]`.)
