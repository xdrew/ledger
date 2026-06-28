## Context

Idempotency makes mutating requests retry-safe. A client sends an `Idempotency-Key`; the
first request executes and its response is stored; retries replay that response; in-flight
duplicates are rejected; reusing a key for a *different* payload is an error. The hard part is
concurrency: two duplicates arriving at once must produce exactly one state change.

This is deliberately **not** event-sourced. ES exists for domains where history/audit is a
real requirement (accounts, ledger, transfers). The idempotency record has no such
requirement — it is operational bookkeeping with a TTL — so it is a plain mutable table.
Applying ES here would add streams, serialization, and replay for zero benefit. This restraint
is a design point of the project (ADR-004).

## Goals / Non-Goals

**Goals:**
- A plain `idempotency_keys` table and an `IdempotencyStore` port with `begin`/`complete`.
- Outcomes: Begun, InProgress (→409), Mismatch (→422), Completed(stored response → replay).
- Exactly-once state change under concurrent duplicates (DB-enforced).
- Configurable TTL; expired completed keys are reusable.
- An in-memory double for fast unit tests of the classification logic.

**Non-Goals (later):** the HTTP middleware that reads the header and maps outcomes to status
codes / captures the response (`add-http-api`); using idempotency for message-bus commands;
a background cleanup job for expired rows (expiry is handled lazily on `begin`).

## Decisions

### D1: Plain mutable table, keyed by (idempotency_key, route)
One row per (key, route). `route` scopes the key to an endpoint so the same key on different
endpoints doesn't collide. Columns: `idempotency_key`, `route`, `request_hash`,
`status` (`in_progress`|`completed`), `response_status`, `response_headers` (JSONB),
`response_body`, `created_at`, `completed_at`, `expires_at`. UNIQUE `(idempotency_key, route)`.

- *Alternatives rejected:* event-sourcing the idempotency log (cost without audit benefit —
  the whole point of this change); keying by request hash alone (can't detect a key reused for
  a different payload, which must be a 422).

### D2: Atomic claim via INSERT ... ON CONFLICT DO NOTHING
`begin` attempts `INSERT (key, route, request_hash, status='in_progress') ON CONFLICT
(idempotency_key, route) DO NOTHING RETURNING …`. If a row is returned, this caller is the
sole winner → **Begun**. Otherwise a row already exists; load it and classify. Postgres
guarantees exactly one concurrent inserter wins, which is the exactly-once property — without
application-level locking.

- *Alternatives rejected:* `SELECT … FOR UPDATE` then insert (a non-existent row can't be
  locked, so two callers both see "absent" and race); advisory locks (stateful, leak-prone).

### D3: Classification of an existing row
When the insert finds an existing row:
1. If it is **completed but expired** (`expires_at < now()`), reclaim it: atomically reset it
   to `in_progress` with the new `request_hash` (conditional `UPDATE … WHERE expires_at <
   now()`); if the update wins → **Begun**, else re-read and continue.
2. Else if `request_hash` differs → **Mismatch** (422).
3. Else if `status = in_progress` → **InProgress** (409).
4. Else (`completed`, not expired) → **Completed** with the stored response (replay).

### D4: TTL handled lazily on begin; in-progress has no TTL
`complete` sets `expires_at = now() + ttl` (configurable, default e.g. 24h). Expiry is enforced
when a later `begin` reclaims an expired row (D3.1) — no background sweeper in this change.
`in_progress` rows do not expire here; a stuck-request lease/timeout is noted as a follow-up
(the runbook will cover draining).

- *Alternatives rejected:* a cron cleanup (unnecessary for correctness; lazy reclaim suffices);
  expiring in-progress rows now (needs a lease policy better defined alongside the HTTP layer).

### D5: `StoredResponse` is plain data, not a framework Response
`StoredResponse` holds an integer status, a header map, and a string body — enough to replay an
HTTP response without depending on Symfony's HttpFoundation. `add-http-api` maps between a real
`Response` and this DTO. This keeps the idempotency capability framework-agnostic and unit-testable.

### D6: Port + DBAL adapter + in-memory double
`IdempotencyStore` is a port. `DbalIdempotencyStore` is production; `InMemoryIdempotencyStore`
implements the same contract for unit tests of replay/mismatch/TTL classification. The
exactly-once concurrency guarantee is verified by an integration test issuing concurrent
`begin`s against Postgres (separate connections).

## Risks / Trade-offs

- **Stuck `in_progress` keys block retries until reclaimed** → Mitigation: TTL reclaim covers
  completed keys; an in-progress lease/timeout is a documented follow-up (runbook: drain).
- **Storing response bodies grows the table** → Mitigation: TTL bounds retention; bodies are
  small (JSON); a sweeper can be added if needed.
- **Expired-row reclaim has a brief race window** → Mitigation: the conditional `UPDATE …
  WHERE expires_at < now()` is atomic; the loser re-reads and classifies. Still exactly-once.

## Open Questions

- Default TTL value and whether it varies per route — start with a single configurable default.
- In-progress lease duration — deferred to the HTTP layer where request timeouts are known.
