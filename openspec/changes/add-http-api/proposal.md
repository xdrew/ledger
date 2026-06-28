## Why

Everything built so far has no outward surface. This change adds the **HTTP API** — the way
clients open accounts, deposit, transfer, and read balances/statements — and finally wires the
pieces together: commands/queries dispatched over the message bus, the idempotency store applied
to mutating requests, RFC 9457 errors, and an OpenAPI 3.1 contract the responses are tested against.

## What Changes

- Enable the **Symfony HTTP stack** (router, controllers, a front controller) — the project was
  console-only until now — and serve it in dev via the PHP built-in server. (Running it under
  **RoadRunner** is deployment's job; the application is identical either way.)
- Dispatch through **`thesis/message-bus`**: commands (`OpenAccount`, `DepositFunds`,
  `InitiateTransfer`) and queries (`GetAccountBalance`, `GetTransfer`, `GetAccountStatement`),
  with handlers calling the existing aggregates/orchestrator and the projection read models.
- Endpoints: `POST /accounts`, `GET /accounts/{id}`, `POST /accounts/{id}/deposits`,
  `POST /transfers`, `GET /transfers/{id}`, `GET /accounts/{id}/statement`.
- **API-key auth** via a header; missing/invalid → `401` problem+json.
- **Idempotency** on mutating endpoints via the `Idempotency-Key` header and the existing
  `IdempotencyStore`: replay a completed key, `409` for in-flight, `422` for a reused key with a
  different payload.
- **RFC 9457 problem+json** for every error (validation, auth, not-found, conflict, domain errors).
- **Request validation**; invalid bodies → `422` problem+json with field details.
- An **OpenAPI 3.1** document checked into the repo and served; a **contract test** asserts
  responses conform to it.

## Capabilities

### New Capabilities
- `api`: the HTTP surface — account/transfer/statement endpoints dispatched via the message bus,
  with API-key auth, idempotent mutations, request validation, RFC 9457 errors, and an OpenAPI
  3.1 contract.

### Modified Capabilities
<!-- None at the spec level; this composes existing capabilities through their ports. -->

## Impact

- **New code:** `App\Api\` (controllers, request DTOs + validation, an auth listener, an
  idempotency listener, an RFC 9457 exception listener, response presenters); `App\*\Application`
  command/query messages + handlers; message-bus wiring; the OpenAPI document; a front controller
  (`public/index.php`) and routing/framework HTTP config.
- **Dependencies (new):** `thesis/message-bus`; `symfony/validator`; PSR-7 bridge
  (`nyholm/psr7`, `symfony/psr-http-message-bridge`); dev: an OpenAPI response validator
  (`league/openapi-psr7-validator`) for the contract test.
- **Read-after-write:** queries read from projections (eventual consistency, ADR-003); the
  contract/e2e tests run the projector to catch up before asserting reads.
- **Depends on** accounts, ledger, transfers, idempotency, projections. RoadRunner serving,
  the production image, and the worker host come in `add-deployment`.
