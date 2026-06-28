## Context

This is the integration layer: a thin HTTP adapter over the domain, served by RoadRunner and built
with the **same conventions as the travel project** (which the user has pointed to as the
reference). Controllers translate HTTP to commands/queries (the `CommandBus` from
`add-message-bus`, the projection query views) and present results; cross-cutting concerns (auth,
idempotency, validation, errors) are kernel listeners / a security authenticator so controllers
stay tiny and carry only their OpenAPI attributes.

Constraints from `project.md`: RoadRunner is the app server; CQRS via the message bus; single
API-key auth; the domain stays framework-free (all Symfony lives in `App\Api` and
`App\Infrastructure`). Where travel's choices are domain-specific (bearer JWT for human users; a
plain error envelope) we adapt them to the ledger (an API key for a service backend) and otherwise
copy travel verbatim.

## Goals / Non-Goals

**Goals:**
- The six business endpoints + `health` + `openapi`, dispatched via the bus / query views,
  returning JSON, served under RoadRunner.
- API-key auth via the Symfony firewall, idempotent mutations, request validation, a JSON error
  envelope.
- An OpenAPI 3.1 document generated from `Action` attributes, served and checked by a contract test.
- Runnable via `docker compose up` (`rr serve`) and testable on Symfony's HTTP kernel
  (`WebTestCase`), no running RoadRunner needed for the suite.

**Non-Goals (later):** the production multi-stage image, worker host, Helm (`add-deployment`); auth
beyond a single static API key (no users/roles/JWT); statement pagination; the NL statement query.

## Decisions

### D1: Serve under RoadRunner via `baldinof/roadrunner-bundle` (the travel setup)
`public/index.php` returns the Kernel through `vendor/autoload_runtime.php`; the bundle's runtime
(`APP_RUNTIME: Baldinof\RoadRunnerBundle\Runtime\Runtime`) drives the worker loop. `.rr.yaml`
(prod) and `.rr.dev.yaml` (dev) configure the HTTP plugin and worker pool; the `rr` binary is added
to the dev image and the compose `http` service runs `rr serve -c .rr.dev.yaml`. PSR-7 ⇄
HttpFoundation bridging is handled by the bundle (`nyholm/psr7` + the Symfony bridge). Tests use
`WebTestCase` (kernel-level), so the suite is fast and the server config is verified by a smoke run.

- *Alternatives rejected:* raw `spiral/roadrunner-http` wiring (travel uses the baldinof bundle, so
  we do too); PHP-FPM/Apache (the project standardized on RoadRunner); deferring RoadRunner to
  deployment (the reviewer chose to wire it here).

### D2: Invokable `Action` controllers in per-endpoint directories (travel layout)
Each endpoint is `App\Api\{Module}\{Operation}\Action` with a `#[Route]` on `__invoke`, a sibling
`Request` DTO (writes, via `#[MapRequestPayload]`) and a `Response` DTO implementing
`JsonSerializable`. `__invoke` builds a command and dispatches it on the `CommandBus`, or calls a
projection query view for reads; it holds no business logic. This one-class-per-endpoint convention
is exactly what the OpenAPI generator scans (D6).

### D3: Routing & DI adapted to the ledger's YAML config
Travel registers routes/controllers with per-module PHP config (`routing.php` + `di.php`). The
ledger uses YAML, so we keep that style: a `config/routes/api.yaml` imports attribute routes from
`src/Api` under the `/api` prefix with `json` format, and `config/services.yaml` loads
`App\Api\**\Action` tagged `controller.service_arguments`. Same outcome (attribute-discovered
invokable controllers), consistent with the existing project config.

### D4: Cross-cutting concerns as listeners + a security authenticator
- **Auth**: a custom `ApiKeyAuthenticator` plugged into the Symfony Security firewall (as travel
  plugs in its `PassportTokenAuthenticator`). It reads the API-key header and validates it against
  the configured key; `access_control` requires authentication on `/api/*` while `health`/`openapi`
  are `PUBLIC_ACCESS` and carry `#[OpenApiPublic]`. Failure → `AuthenticationException` → `401`.
- **Idempotency**: a kernel listener on mutating routes reads `Idempotency-Key`, hashes the
  request, and calls `IdempotencyStore::begin`. Begun → proceed and `complete(...)` with the
  captured `StoredResponse`; Completed → replay; InProgress → `409`; Mismatch → `422`. (Travel has
  no idempotency; this is ledger-specific but written in the same listener style.)
- **Errors**: an `ApiExceptionListener` (travel's `KernelEvents::EXCEPTION` listener) maps
  throwables to status via `match()` and renders the envelope.

### D5: Error envelope is travel's JSON shape, not RFC 9457
The listener renders `{ "message": "<safe message>" }` (adding `{ "errors": [...] }` with field
violations for validation failures), matching travel — **not** `application/problem+json`. Because
every ledger domain exception extends `\RuntimeException` (travel relies on `\DomainException`/
`\InvalidArgumentException`, which the ledger does not use), the `match()` lists ledger exceptions
explicitly:

| Throwable | Status |
| --- | --- |
| `HttpExceptionInterface` | its own status |
| `AuthenticationException` / `AccessDeniedException` | `401` / `403` |
| `ValidationFailedException` (from `#[MapRequestPayload]`) | `422` (+ field errors) |
| `AccountNotFound`, `TransferNotFound`, `JournalEntryNotFound` | `404` |
| `InsufficientFunds`, `AccountNotActive`, `ClosedAccountPosting`, `InvalidTransferTransition`, `TransferNotReversible`, `ConcurrencyConflict` | `409` |
| `InvalidAmount`, `CurrencyMismatch`, `InvalidLegAmount`, `UnbalancedEntry` | `422` |
| default | `500` (message replaced with a generic string; no internals leaked) |

- *Alternatives rejected:* RFC 9457 problem+json (the earlier draft; dropped to match travel);
  marker interfaces on exceptions to enable a generic `match` (heavier; the explicit table is clear
  and localized to one listener).

### D6: OpenAPI 3.1 from a custom reflection generator (travel's `OpenApiGenerator`)
`App\Infrastructure\OpenApi\OpenApiGenerator` reflects over the `Action` classes in `App\Api` and
builds the 3.1 document: each `__invoke`'s `#[Route]` (path/methods/operationId), path params, the
`#[MapRequestPayload]` `Request` DTO (→ a component schema from its constructor params + public
properties, required from `isOptional()`), the `JsonSerializable` `Response` (→ response schema),
and the attributes `#[OpenApiPublic]` (no security), `#[ResponseStatus]` (success code), `#[Tag]`,
`#[QueryParam]`. The security scheme is the API key (header) rather than travel's bearer JWT.
PHP→OpenAPI type mapping mirrors travel (`int`→integer, `float`→number, `bool`→boolean,
`array`→object, nullability from reflection). Served by `OpenApiAction` at `/api/openapi.{format}`
(`json|yaml`) and written to disk by an `api:openapi:generate` command. Verification needs no
OpenAPI-validator (travel approach): a **generator test** asserts the document is well-formed 3.1
with the expected paths/operations/schemas and the API-key security scheme, and per-endpoint
**functional tests** assert the real response shapes (validating responses against a schema derived
from the same DTOs would be largely circular).

- *Alternatives rejected:* `nelmio/api-doc-bundle` / `swagger-php` (travel uses its own reflection
  generator); `league/openapi-psr7-validator` (re-checks code-generated inference; functional shape
  tests cover it); a hand-maintained `openapi.yaml` (drifts from the code).

### D7: Transfer endpoint returns the resulting resource
`POST /api/transfers` runs the saga synchronously and returns `201` (`#[ResponseStatus(201)]`) with
the transfer resource and its terminal `status` (`completed`/`failed`) + `failureReason`.
Insufficient funds is a successful request with `status: failed` (not an HTTP error); a retriable
`ConcurrencyConflict` → `409`.

### D8: Reads serve projections (eventual consistency, ADR-003)
`GET` actions read the `account_balances` / `account_statement` read models and the transfer
stream, never the write side. Reads are eventually consistent with writes; an unknown id from a
read model → `NotFoundHttpException` → `404`. The contract/e2e tests run the projector to catch up
before asserting reads.

## Risks / Trade-offs

- **RoadRunner/baldinof adds runtime/build moving parts (binary, worker, runtime, .rr.yaml)** →
  Mitigation: tests run on the Symfony kernel (no RoadRunner needed); a single smoke run verifies
  the server boots and serves `/api/health`.
- **baldinof/roadrunner-bundle Symfony 8 compatibility** → Mitigation: pin the Symfony
  8-compatible release and verify `composer require` resolves at implementation (as we did for the
  doctrine bundles); fall back to a thin runtime if needed.
- **The reflection generator can under-describe responses** → Mitigation: functional tests assert
  the real response shapes per endpoint; the generator test asserts the document's structure.
- **Eventual consistency surprises clients** → Mitigation: documented (ADR-003); reads carry the
  projection `version`; tests catch up explicitly.
- **Idempotency listener must capture the exact response to replay** → Mitigation: capture status,
  headers, body into `StoredResponse` on the terminating response; covered by tests.

## Open Questions

- RoadRunner worker count / config defaults — start minimal; tuned in deployment/observability.
- How much schema detail the reflection generator infers (enums, nullability, formats) — start with
  the travel-project coverage; deepen if the contract test demands it.
