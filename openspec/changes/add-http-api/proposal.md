## Why

Everything built so far has no outward surface. This change adds the **HTTP API** — clients open
accounts, deposit, transfer, and read balances/statements — built with the **same conventions as
the travel project**: invokable `Action` controllers in per-endpoint directories, attribute
routing, request/response DTOs, a reflection-based **OpenAPI 3.1** generator (no `nelmio`/
`swagger-php`), a kernel exception listener that renders **RFC 9457** problem+json errors, and **RoadRunner**
(via `baldinof/roadrunner-bundle`) as the app server. Mutating requests dispatch ledger commands
through the message bus (`add-message-bus`) and are made idempotent with the existing
`IdempotencyStore`; reads go straight to projections.

## What Changes

- Enable the **Symfony HTTP stack** and serve it under **RoadRunner** the travel way:
  `public/index.php` returning the Kernel, `baldinof/roadrunner-bundle` + `symfony/runtime`,
  `.rr.yaml` / `.rr.dev.yaml`, and a compose `http` service running `rr serve -c .rr.dev.yaml`.
- **Endpoints** as invokable `Action` classes (one directory per endpoint, mirroring
  `App\Api\{Module}\{Operation}\{Action,Request,Response}`):
  - `POST /api/accounts`, `GET /api/accounts/{id}`, `POST /api/accounts/{id}/deposits`
  - `POST /api/transfers`, `GET /api/transfers/{id}`
  - `GET /api/accounts/{id}/statement`
  - plus public `GET /api/health` and `GET /api/openapi.{json,yaml}`.
  Write actions dispatch commands via the `CommandBus` from `add-message-bus`; read actions call
  the projection query views directly.
- **API-key authentication** through the **Symfony Security firewall** with a custom
  `ApiKeyAuthenticator` (travel uses the firewall + a custom authenticator for its bearer JWT; we
  substitute an API key because the ledger is a service-to-service backend with no end users).
  `access_control` requires a key on `/api/*`; `#[OpenApiPublic]` marks `health`/`openapi` public.
  Missing/invalid key → `401`.
- **Idempotency** on mutating endpoints via the `Idempotency-Key` header and the existing
  `IdempotencyStore` (a ledger-specific addition travel does not have): replay a completed key,
  `409` in-flight, `422` reused-key-different-payload. Implemented as a kernel listener in the same
  style as the other cross-cutting listeners.
- **Error handling**: an `ApiExceptionListener` (travel's listener mechanism) maps throwables to
  HTTP status via a `match()` and renders **RFC 9457** `application/problem+json`
  (`type`/`title`/`status`/`detail`, plus an `errors` member for validation). Because every ledger
  domain exception extends `\RuntimeException`, the mapping is explicit per exception (e.g.
  `*NotFound` → `404`,
  `InsufficientFunds`/`AccountNotActive`/transition errors → `409`/`422`, `ConcurrencyConflict` →
  `409`, validation → `422`, else `500`).
- **Request validation** with typed `Request` DTOs (`#[MapRequestPayload]` + `symfony/validator`
  constraints); failures surface as `422` rendered through the error listener.
- **OpenAPI 3.1** by a custom reflection generator copied in spirit from the travel project
  (`App\Infrastructure\OpenApi\OpenApiGenerator`): it scans `App\Api` `Action` classes, reads each
  `#[Route]`, the `#[MapRequestPayload]` `Request` DTO (→ component schema), the `JsonSerializable`
  `Response` DTO (→ response schema), path params, and the attributes `#[OpenApiPublic]`,
  `#[ResponseStatus]`, `#[Tag]`, `#[QueryParam]`; the security scheme is the API key (header).
  Served by an `OpenApiAction` at `/api/openapi.{format}` and dumpable via an
  `api:openapi:generate` console command. A **generator/contract test** asserts the document is
  well-formed 3.1 with the expected paths, operations, and schemas; per-endpoint **functional
  tests** assert the real response shapes (no OpenAPI-validator dependency — the travel approach).

## Capabilities

### New Capabilities
- `api`: the HTTP surface served by RoadRunner — account/transfer/statement endpoints dispatched
  via the message bus, with API-key auth, idempotent mutations, request validation, RFC 9457
  problem+json errors, and an attribute-generated OpenAPI 3.1 contract. Conventions follow the
  travel project (errors use RFC 9457 rather than travel's plain envelope).

### Modified Capabilities
<!-- None at the spec level; composes existing capabilities through the message bus. -->

## Impact

- **New code:** `App\Api\` (invokable `Action` controllers + `Request`/`Response` DTOs per
  endpoint; the `ApiExceptionListener`; an idempotency listener; the `ApiKeyAuthenticator`); a
  custom `App\Infrastructure\OpenApi` generator + attributes + `OpenApiAction` + console command;
  `public/index.php`; routing/framework/security HTTP config; `.rr.yaml` / `.rr.dev.yaml`.
- **Dependencies (new):** `baldinof/roadrunner-bundle` (+ `spiral/roadrunner-*` it pulls in) and
  `symfony/runtime` for the app server; `symfony/security-bundle` for the firewall;
  `symfony/validator` for request validation. No OpenAPI library and no OpenAPI-validator — the
  generator and its tests are hand-rolled, as in travel. The `rr` binary is added to the dev image.
  (baldinof's Symfony 8-compatible release is used; verified at implementation.)
- **Read-after-write:** queries read from projections (eventual consistency, ADR-003); the
  contract/e2e tests run the projector to catch up before asserting reads.
- **Depends on** `add-message-bus` (command dispatch) and accounts, transfers, idempotency,
  projections. The production multi-stage image, the worker host, and Helm come in `add-deployment`.
