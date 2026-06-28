> Depends on `project-runtime` (DBAL connection, migrations). Independent of the event store —
> this is a plain mutable table by design (ADR-004). No new third-party dependencies.

## 1. Values & outcomes

- [ ] 1.1 Implement `IdempotencyKey` (non-empty string VO) and `StoredResponse` (int status, header map, string body).
- [ ] 1.2 Implement the `begin` outcome types: Begun, InProgress, Mismatch, Completed(`StoredResponse`) — modelled so callers can branch exhaustively.
- [ ] 1.3 Implement a TTL configuration value (configurable duration, sensible default).

## 2. Store port & in-memory double

- [ ] 2.1 Define the `IdempotencyStore` port: `begin(key, route, requestHash): Outcome` and `complete(key, route, StoredResponse): void`.
- [ ] 2.2 Implement `InMemoryIdempotencyStore` honouring the classification rules (begin/replay/in-progress/mismatch/TTL).
- [ ] 2.3 Unit-test the in-memory store: fresh begin; replay completed; in-progress conflict; payload mismatch; TTL reclaim.

## 3. PostgreSQL (DBAL) adapter

- [ ] 3.1 Migration creating `idempotency_keys`: `idempotency_key`, `route`, `request_hash`, `status`, `response_status`, `response_headers` JSONB, `response_body`, `created_at`, `completed_at`, `expires_at`; UNIQUE `(idempotency_key, route)`. Reversible `down()`.
- [ ] 3.2 Implement `DbalIdempotencyStore.begin` via `INSERT … ON CONFLICT (idempotency_key, route) DO NOTHING RETURNING …`; on conflict, classify the existing row (expired-reclaim / mismatch / in-progress / completed).
- [ ] 3.3 Implement `DbalIdempotencyStore.complete` (store response, mark completed, set `expires_at = now() + ttl`).

## 4. Wiring

- [ ] 4.1 Bind the `IdempotencyStore` port to the DBAL adapter; expose the TTL as configuration (env-driven default).

## 5. Tests

- [ ] 5.1 Integration: begin → complete → replay round-trip against Postgres (stored response returned).
- [ ] 5.2 Integration: in-progress conflict and payload-mismatch outcomes against Postgres.
- [ ] 5.3 Integration (concurrency): two concurrent `begin`s for the same key on separate connections yield exactly one Begun; the other is InProgress/Completed — exactly one state change.
- [ ] 5.4 Integration: TTL reclaim — a completed-but-expired key is begun anew.

## 6. Verification & gate

- [ ] 6.1 Confirm the "done" criterion: concurrent duplicate begins produce exactly one state change.
- [ ] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration suites; `openspec validate add-idempotency --strict` passes.
