## Context

This is the integration layer: a thin HTTP adapter over the domain, served by RoadRunner.
Controllers translate HTTP to commands/queries on the message bus (from `add-message-bus`) and
present results; cross-cutting concerns (auth, idempotency, validation, error formatting) are
request/response listeners so controllers stay small and carry only their OpenAPI attributes.

Constraints from `project.md`: RoadRunner is the app server (HTTP plugin); CQRS via the message
bus; errors as RFC 9457; single API-key auth; the domain stays framework-free (all Symfony lives
in `App\Api`).

## Goals / Non-Goals

**Goals:**
- The six endpoints, dispatched via the buses, returning JSON, served under RoadRunner.
- API-key auth, idempotent mutations, request validation, RFC 9457 errors.
- An OpenAPI 3.1 document generated from controller attributes, served and enforced by a contract test.
- Runnable via `docker compose up` (RoadRunner) and testable via Symfony's HTTP kernel.

**Non-Goals (later):** the production multi-stage image, worker host, Helm (`add-deployment`); auth
beyond a single static API key; statement pagination; the NL statement query.

## Decisions

### D1: Serve under RoadRunner (HTTP plugin)
A `public/index.php` front controller runs under a Symfony RoadRunner runtime; `.rr.yaml`
configures the HTTP plugin and worker; the `rr` binary is added to the dev image and a compose
`http` service runs `rr serve`. PSR-7 ⇄ HttpFoundation bridging via `nyholm/psr7` +
`symfony/psr-http-message-bridge`. Tests use Symfony's `WebTestCase` (kernel-level), which needs no
running RoadRunner — so the suite is fast and the server config is verified by a smoke run.

- *Alternatives rejected:* PHP-FPM/Apache (the project standardized on RoadRunner); deferring
  RoadRunner to deployment (the reviewer chose to wire it here).

### D2: Invokable `*Action` controllers dispatching via the buses
Each endpoint is a single-action invokable `*Action` class with a `#[Route]` on `__invoke`,
taking a `#[MapRequestPayload]` request DTO (writes) and returning a `JsonSerializable` response
DTO. `__invoke` builds a command/query and dispatches it on the bus from `add-message-bus`; it
holds no business logic. This one-class-per-endpoint convention (as in the travel project) is also
what the OpenAPI generator scans (D6).

### D3: Cross-cutting concerns as kernel listeners
- **Auth**: a request listener checks the API-key header; failure → `401` problem+json, short-circuit.
- **Idempotency**: a listener on mutating routes reads `Idempotency-Key`, hashes the request, and
  calls `IdempotencyStore::begin`. Begun → proceed and `complete(...)` with the captured
  `StoredResponse` on the response; Completed → replay; InProgress → `409`; Mismatch → `422`.
- **Errors**: an exception listener maps throwables to RFC 9457 problem+json — validation → `422`,
  auth → `401`, not-found → `404`, `ConcurrencyConflict` → `409`, domain violations → `409`/`422`,
  else `500` (no internals leaked).

### D4: Request validation with typed DTOs
Each mutating endpoint has a request DTO from the JSON body, validated with `symfony/validator`
(required fields, positive integer amounts, currency format, uuid ids). Invalid → `422`
problem+json listing fields. Money is integer minor units + currency, never floats.

### D5: Read endpoints serve projections (eventual consistency, ADR-003)
`GET` endpoints read the `account_balances`/`account_statement` read models and the transfer
stream, never the write side. Reads are eventually consistent with writes; the contract/e2e tests
run the projector to catch up before asserting reads.

### D6: OpenAPI 3.1 from a custom reflection generator (travel-project approach)
A hand-rolled `OpenApiGenerator` (no `nelmio`/`swagger-php`) reflects over the invokable `*Action`
controllers in `App\Api` and builds the 3.1 document: it reads each `__invoke`'s `#[Route]`
(path/methods), path parameters, the `#[MapRequestPayload]` request DTO (→ a component schema from
its constructor/properties), the `JsonSerializable` return type (→ response schema), and a few
small attributes — `#[ResponseStatus]` (success code), `#[Tag]`, `#[OpenApiPublic]` (no auth). The
security scheme is the API key (header). It is served by an `OpenApiAction` at
`/openapi.{json,yaml}` and written to disk by a `openapi:generate` console command. A contract
test exercises each endpoint via `WebTestCase` and validates responses against the generated
document with `league/openapi-psr7-validator`.

- *Alternatives rejected:* `nelmio/api-doc-bundle` / `swagger-php` (the reviewer chose the
  travel-project's dependency-free reflection generator); a hand-maintained `openapi.yaml` (drifts
  from the code).

### D7: Transfer endpoint returns the resulting resource
`POST /transfers` runs the saga synchronously and returns `201` with the transfer resource and its
terminal `status` (`completed`/`failed`) + `failureReason`. Insufficient funds is a successful
request with `status: failed` (not an HTTP error); a retriable `ConcurrencyConflict` → `409`.

## Risks / Trade-offs

- **RoadRunner adds runtime/build moving parts (binary, worker, .rr.yaml)** → Mitigation: tests run
  on the Symfony kernel (no RoadRunner needed); a single smoke run verifies the server boots.
- **The reflection generator can under-describe responses** → Mitigation: the contract test fails
  if a response doesn't validate, forcing the DTOs/attributes to stay complete.
- **Eventual consistency surprises clients** → Mitigation: documented (ADR-003); reads carry the
  projection `version`; tests catch up explicitly.
- **Idempotency listener must capture the exact response to replay** → Mitigation: capture status,
  headers, body into `StoredResponse` on the terminating response; covered by tests.

## Open Questions

- RoadRunner worker count / config defaults — start minimal; tuned in deployment/observability.
- How much schema detail the reflection generator infers (enums, nullability, formats) — start
  with the travel-project coverage; deepen if the contract test demands it.
