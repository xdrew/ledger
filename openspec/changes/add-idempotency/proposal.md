## Why

Mutating requests must be safe to retry. Networks drop responses; clients resend. Without
deduplication, a retried "create transfer" could move money twice. The system needs an
**idempotency layer**: the same `Idempotency-Key` replays the original outcome instead of
re-executing, and concurrent duplicates collapse to exactly one state change.

This capability is also where the project deliberately **does not** use Event Sourcing. The
idempotency store is a **plain mutable table** — there is no audit/history requirement here,
and over-applying ES would be cost without benefit (documented in ADR-004). It is built now,
before the HTTP API and transfer saga that will use it.

## What Changes

- Introduce a plain `idempotency_keys` table keyed by **(idempotency key, route)**, storing a
  request hash, a status (`in_progress` | `completed`), the stored response, and timestamps
  with a TTL.
- Introduce an **`IdempotencyStore`** (port + DBAL adapter + in-memory double) with two
  operations:
  - `begin(key, route, requestHash)` → an outcome: **Begun** (first time — caller proceeds),
    **InProgress** (another request holds the key → maps to `409 Conflict`), **Mismatch**
    (same key, different payload → maps to `422`), or **Completed**(stored response → replay).
  - `complete(key, route, response)` → persist the response and mark the key completed with a
    TTL-based expiry.
- Enforce **exactly one state change** under concurrency via a unique `(key, route)` constraint
  and an atomic insert: only one concurrent request becomes Begun; the rest replay or conflict.
- Make the **TTL configurable**; an expired completed key is treated as new (reusable).

The HTTP wiring (reading the `Idempotency-Key` header, mapping outcomes to `409`/`422`,
capturing/replaying the real HTTP response) lands with `add-http-api`. This change delivers
the framework-agnostic mechanism and proves the concurrency guarantee against PostgreSQL.

## Capabilities

### New Capabilities
- `idempotency`: deduplication of mutating requests via an `Idempotency-Key` — reserve/replay
  outcomes, payload-mismatch detection, configurable TTL, and exactly-once state change under
  concurrent duplicates, backed by a plain (non-event-sourced) table.

### Modified Capabilities
<!-- None. -->

## Impact

- **New code:** `App\Idempotency\` — `IdempotencyKey`, `StoredResponse`, the outcome types,
  the `IdempotencyStore` port, `DbalIdempotencyStore`, `InMemoryIdempotencyStore`, and a TTL
  configuration value.
- **Database:** new `idempotency_keys` table (a migration) with a unique `(key, route)`
  constraint — a plain mutable table, **not** in the event store.
- **Depends on** `project-runtime` (DBAL, migrations). Independent of the event store. No new
  third-party dependencies.
- **Downstream:** `add-http-api` wires this into request handling; `add-transfers-saga`
  benefits from retry-safe transfer creation.
