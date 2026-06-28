## Why

Everything built so far has no outward surface. This change adds the **HTTP API** — clients open
accounts, deposit, transfer, and read balances/statements — served by **RoadRunner**, dispatching
through the message bus, applying idempotency to mutating requests, returning RFC 9457 errors, and
exposing an **OpenAPI 3.1** contract (generated from controller attributes) that responses are
tested against.

## What Changes

- Enable the **Symfony HTTP stack** (router, controllers, front controller) and serve it under
  **RoadRunner** (HTTP plugin): the `rr` binary in the image, a `.rr.yaml`, the RoadRunner
  runtime, and a compose `http` service.
- Endpoints: `POST /accounts`, `GET /accounts/{id}`, `POST /accounts/{id}/deposits`,
  `POST /transfers`, `GET /transfers/{id}`, `GET /accounts/{id}/statement` — each dispatching the
  commands/queries from `add-message-bus`.
- **API-key auth** via a header; missing/invalid → `401` problem+json.
- **Idempotency** on mutating endpoints via the `Idempotency-Key` header and the existing
  `IdempotencyStore`: replay a completed key, `409` in-flight, `422` reused-key-different-payload.
- **RFC 9457 problem+json** for every error; **request validation** (`422` with field details).
- **OpenAPI 3.1 generated from PHP attributes** on the controllers (via `nelmio/api-doc-bundle`),
  served as JSON; a **contract test** asserts responses conform to the generated document.

## Capabilities

### New Capabilities
- `api`: the HTTP surface served by RoadRunner — account/transfer/statement endpoints dispatched
  via the message bus, with API-key auth, idempotent mutations, request validation, RFC 9457
  errors, and an attribute-generated OpenAPI 3.1 contract.

### Modified Capabilities
<!-- None at the spec level; composes existing capabilities through the message bus. -->

## Impact

- **New code:** `App\Api\` (controllers with OpenAPI attributes, request DTOs + validation, an
  auth listener, an idempotency listener, an RFC 9457 exception listener, response presenters);
  `public/index.php`; routing/framework HTTP config; `.rr.yaml`.
- **Dependencies (new):** RoadRunner (`spiral/roadrunner-http` + a Symfony RoadRunner runtime,
  `nyholm/psr7`, `symfony/psr-http-message-bridge`); `symfony/validator`;
  `nelmio/api-doc-bundle` (attribute-driven OpenAPI); dev: `league/openapi-psr7-validator`
  (contract test). The `rr` binary is added to the dev image.
- **Read-after-write:** queries read from projections (eventual consistency, ADR-003); the
  contract/e2e tests run the projector to catch up before asserting reads.
- **Depends on** `add-message-bus` (command/query dispatch) and accounts, transfers, idempotency,
  projections. The production multi-stage image, the worker host, and Helm come in `add-deployment`.
