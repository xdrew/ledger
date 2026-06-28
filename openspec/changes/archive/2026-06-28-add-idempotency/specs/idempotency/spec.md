## ADDED Requirements

### Requirement: First use of a key begins processing

The first `begin` for an `(idempotency key, route)` pair SHALL reserve the key (status
`in_progress`) and return a Begun outcome, signalling the caller to process the request.

#### Scenario: A fresh key is begun

- **WHEN** `begin` is called with a key/route never seen before
- **THEN** the outcome is Begun and the key is reserved as in-progress

### Requirement: Completed keys replay the stored response

A completed key's stored response SHALL be replayed: a subsequent `begin` with the same key,
route, and request hash returns a Completed outcome carrying the stored response, without the
caller re-processing the request.

#### Scenario: Replaying a completed key

- **WHEN** a key is begun, completed with a stored response, and then begun again with the same request hash
- **THEN** the outcome is Completed and exposes the stored response (status, headers, body)

### Requirement: In-flight keys are rejected as conflict

An in-flight key SHALL be rejected: while a key is reserved (in-progress) and not yet completed,
a second `begin` with the same key and route returns an InProgress outcome (which the HTTP
layer maps to `409 Conflict`).

#### Scenario: A second request while the first is in flight

- **WHEN** a key is begun and, before it completes, `begin` is called again for the same key/route
- **THEN** the second outcome is InProgress

### Requirement: A reused key with a different payload is a mismatch

A key reused with a different payload SHALL be rejected: if `begin` finds an existing key/route
with a **different** request hash, it returns a Mismatch outcome (which the HTTP layer maps to
`422`) and does not alter the existing record.

#### Scenario: Same key, different payload

- **WHEN** a key is begun with one request hash and `begin` is called again with the same key/route but a different request hash
- **THEN** the outcome is Mismatch and the original record is unchanged

### Requirement: Completed keys expire after a configurable TTL

A completed key SHALL be retained for a configurable time-to-live, after which a `begin` with
that key is treated as new (Begun) and the key may be reused.

#### Scenario: Begin after the TTL has elapsed

- **WHEN** a key is completed and its TTL has elapsed, then `begin` is called again with that key
- **THEN** the outcome is Begun (the expired record is reclaimed)

### Requirement: Concurrent duplicates produce exactly one state change

Concurrent duplicate requests SHALL produce exactly one state change: when multiple requests
with the same key and route run concurrently, exactly one receives a Begun outcome and every
other receives InProgress or (once the first completes) Completed — never a second Begun.

#### Scenario: Two concurrent begins for the same key

- **WHEN** two `begin` calls for the same key/route execute concurrently
- **THEN** exactly one returns Begun and the other does not (it is InProgress or Completed)
- **AND** at most one request proceeds to change state
