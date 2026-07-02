# ADR-004 — Where we deliberately avoid event sourcing

Status: Accepted (2026-06-28) · Visible in `src/Idempotency/` and `src/Projections/`.

## Context

Once event sourcing is the write-side backbone (ADR-001), every new piece of state invites the
question "should this be event-sourced too?". Uniform application of a pattern reads as rigor but
is often the opposite: ES pays for itself where history *is* the domain; elsewhere it charges the
same costs (replay, versioning, projections to make the data queryable) and returns nothing.

## Decision

Event sourcing is confined to the **domain aggregates** (accounts, journal entries, transfers).
Two kinds of state are deliberately plain mutable tables:

- **The idempotency store** (`idempotency_keys`, `DbalIdempotencyStore`): a reservation +
  stored-response record with a TTL. Its lifecycle is `begin → complete → expire`; nobody asks
  "how did this idempotency key evolve?". `INSERT … ON CONFLICT DO NOTHING` *is* the concurrency
  semantics — races collapse into one winner by construction.
- **Read models** (`account_balances`, `account_statement`): projections are *derived* state whose
  history is already fully recorded — in the event store they project. Event-sourcing a projection
  would record the history of a history. They are mutable, truncatable, rebuildable (ADR-003).

The checkpoint tables (`projection_checkpoints`, `consumed_events`) follow the same logic: cursors,
not facts.

## Consequences

- ✚ Each store uses the simplest mechanics that give its actual guarantee (unique constraint for
  idempotency; transactional checkpoint for projections) — auditable in minutes.
- ✚ TTL cleanup of idempotency keys is a `DELETE`, not a retention policy on an immutable log.
- ▬ Two persistence idioms coexist; contributors must know which side of the line new state falls
  on. The line is stated here: **is the history itself a domain fact someone will ask about?**
  If not, don't event-source it.
- ▬ No audit trail of idempotency-key usage beyond what request logs capture — accepted, it's
  transport-level plumbing.

## Options considered and rejected

- **Event-source everything ("purity").** The idempotency store as an `IdempotencyKeyReserved` /
  `ResponseStored` stream would need a projection just to answer "is this key used?" — recreating
  the plain table with extra steps, replay cost, and versioning burden. Rejected as
  cargo-culting: over-applying ES is a cost, not a virtue.
- **CRUD the domain too (symmetry in the other direction).** Answered by ADR-001; the domain is
  exactly where history is the product.
- **A separate KV store (Redis) for idempotency.** Another infrastructure dependency, another
  consistency domain, and the reservation must be transactional with nothing — PostgreSQL's unique
  constraint already provides the atomic begin. Rejected on operational footprint.
