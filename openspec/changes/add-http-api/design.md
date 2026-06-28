## Context

This is the integration layer: a thin HTTP adapter over the domain. Controllers translate HTTP
to commands/queries on the message bus and present results; cross-cutting concerns (auth,
idempotency, validation, error formatting) are request/response listeners so controllers stay
small. The domain, repositories, orchestrator, idempotency store, and projections already exist —
this change composes them and exposes them.

Constraints from `project.md`: CQRS dispatched via `thesis/message-bus`; RoadRunner is the
production app server; errors as RFC 9457; single API-key auth; the domain stays framework-free
(all Symfony lives in `App\Api`).

## Goals / Non-Goals

**Goals:**
- The six endpoints, dispatched via the message bus, returning JSON.
- API-key auth, idempotent mutations, request validation, RFC 9457 errors.
- An OpenAPI 3.1 document served and enforced by a contract test.
- Runnable in dev (PHP built-in server) and fully testable via Symfony's HTTP kernel.

**Non-Goals (later):** RoadRunner serving + the production image + worker host (`add-deployment`);
auth beyond a single static API key; pagination/filtering on the statement; the NL statement
query (`add-llm-statement-query`).

## Decisions

### D1: Build the HTTP application now; run it under RoadRunner in deployment
We enable Symfony's router/controllers and a `public/index.php` front controller, and serve it in
dev with `php -S` (a compose `http` command). The application is a standard PSR-15-ish Symfony
HTTP app; RoadRunner is only the runtime/SAPI, added in `add-deployment` (the `rr` binary, worker,
`.rr.yaml`, image). Building/​testing here uses Symfony's `WebTestCase`, which needs no RoadRunner.

- *Alternatives rejected:* pulling RoadRunner in now — drags the binary, worker, and image
  (deployment concerns) into the API change for no testing benefit.

### D2: Dispatch via `thesis/message-bus`; handlers are thin
Controllers build a command/query message and dispatch it on the bus; handlers live in each
context's `Application` layer and call the aggregate/repository or orchestrator (writes) or the
projection view (reads). Cross-cutting bus middleware (correlation id propagation; a transaction
boundary where useful) is configured centrally. The exact bus API is pinned against the library
at implementation.

- *Alternatives rejected:* controllers calling orchestrators/repositories directly — works, but
  the project's architecture (and ADRs) call for CQRS over the bus; centralizing dispatch gives
  one place for correlation/validation/transaction middleware.

### D3: Cross-cutting concerns as kernel listeners
- **Auth**: a request listener checks the API-key header against the configured key; failure →
  `401` problem+json, short-circuiting the request.
- **Idempotency**: a listener on mutating routes reads `Idempotency-Key`, computes a request hash,
  and calls `IdempotencyStore::begin`. Begun → proceed and, on the response, `complete(...)` with
  the captured `StoredResponse`; Completed → replay; InProgress → `409`; Mismatch → `422`.
- **Errors**: an exception listener maps every throwable to RFC 9457 problem+json — validation →
  `422`, auth → `401`, not-found → `404`, `ConcurrencyConflict` → `409`, domain rule violations →
  `409`/`422`, everything else → `500` (no internals leaked).

### D4: Request validation with typed DTOs
Each mutating endpoint has a request DTO populated from the JSON body and validated with
`symfony/validator` (required fields, positive integer amounts, currency format, uuid ids).
Invalid → `422` problem+json listing the offending fields. Money arrives as integer minor units +
currency, never floats.

### D5: Read endpoints serve projections (eventual consistency, ADR-003)
`GET` endpoints read the `account_balances` / `account_statement` read models and the transfer
stream, never the write side. Reads are eventually consistent with writes (the projector/relay
catch up asynchronously); this trade-off is ADR-003. The contract/e2e tests run the projector to
catch up before asserting read responses.

### D6: OpenAPI 3.1 as the contract; tested, not generated-from-code
A hand-maintained `openapi.yaml` (3.1) is checked in and served (e.g. `GET /openapi.json`). A
contract test exercises each endpoint via `WebTestCase` and validates the responses against the
document with `league/openapi-psr7-validator`. The spec is the source of truth for the wire shape.

- *Alternatives rejected:* generating OpenAPI from PHP attributes — couples the contract to code
  annotations; a checked-in document reviewed as an artifact suits a "contract-first" portfolio piece.

### D7: Transfer endpoint returns the resulting resource
`POST /transfers` runs the saga synchronously and returns `201` with the transfer resource,
including its terminal `status` (`completed`/`failed`) and `failureReason`. Business failures
(insufficient funds) are a `completed`-request with `status: failed`, not an HTTP error; a
retriable `ConcurrencyConflict` surfaces as `409`.

## Risks / Trade-offs

- **Large surface in one change** → Mitigation: cross-cutting concerns isolated as listeners;
  could be split (message bus / endpoints / contract) if review prefers — flagged for the reviewer.
- **Eventual consistency surprises clients** → Mitigation: documented (ADR-003); reads carry the
  projection `version`; tests catch up explicitly. A future read-your-writes option can read the
  aggregate.
- **Idempotency listener must capture the exact response to replay** → Mitigation: capture status,
  headers, body into `StoredResponse` on the terminating response; covered by tests.

## Open Questions

- Should the message-bus wiring + handlers be a separate prerequisite change? Defaulting to one
  `api` change; will split if the reviewer prefers smaller PRs.
- Statement pagination — out of scope now; add when needed.
