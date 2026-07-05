# api Specification

## Purpose
TBD - created by archiving change add-http-api. Update Purpose after archive.
## Requirements
### Requirement: Open an account

The API SHALL open an account via `POST /api/accounts` with a currency, returning `201` with the
new account's id and currency.

#### Scenario: Opening an account

- **WHEN** `POST /api/accounts` is called with `{ "currency": "USD" }` and a valid API key and idempotency key
- **THEN** the response is `201` with a JSON body containing the account id and currency `USD`

### Requirement: Read an account balance

The API SHALL return an account's balances via `GET /api/accounts/{id}` from the read model, or
`404` if the account is unknown.

#### Scenario: Reading an existing account

- **WHEN** `GET /api/accounts/{id}` is called for a known account
- **THEN** the response is `200` with available, reserved, total, and version

#### Scenario: Reading an unknown account

- **WHEN** `GET /api/accounts/{id}` is called for an unknown id
- **THEN** the response is `404` `application/problem+json`

### Requirement: Deposit funds

The API SHALL accept a deposit via `POST /api/accounts/{id}/deposits` with a positive amount in the
account's currency, returning `200`/`201` on success.

#### Scenario: Depositing into an account

- **WHEN** `POST /api/accounts/{id}/deposits` is called with `{ "amount": 10000, "currency": "USD" }`
- **THEN** the deposit is applied and the response reflects success

### Requirement: Create a transfer

The API SHALL create a transfer via `POST /api/transfers` between two accounts for a positive
amount, running the saga and returning `201` with the transfer's id and terminal status.

#### Scenario: A funded transfer completes

- **WHEN** `POST /api/transfers` is called for a fully funded source
- **THEN** the response is `201` with the transfer id and status `completed`

#### Scenario: An underfunded transfer is reported as failed

- **WHEN** `POST /api/transfers` is called for a source with insufficient funds
- **THEN** the response is `201` with status `failed` and a failure reason (not an HTTP error)

### Requirement: Read a transfer

The API SHALL return a transfer's status via `GET /api/transfers/{id}`, or `404` if unknown.

#### Scenario: Reading a transfer

- **WHEN** `GET /api/transfers/{id}` is called for a known transfer
- **THEN** the response is `200` with the transfer id and status

### Requirement: Read an account statement

The API SHALL return an account's statement via `GET /api/accounts/{id}/statement` from the read
model, ordered by global position.

#### Scenario: Reading a statement

- **WHEN** `GET /api/accounts/{id}/statement` is called for an account with activity
- **THEN** the response is `200` with the postings/holds in order

### Requirement: Public health endpoint

The API SHALL expose a public `GET /api/health` endpoint that requires no API key and reports
service health.

#### Scenario: Health without a key

- **WHEN** `GET /api/health` is called without an API key
- **THEN** the response is `200` with a health status body

### Requirement: API-key authentication

The API SHALL require a valid API key header on every endpoint except the public ones
(`health`, `openapi`), enforced by the Symfony Security firewall, and reject a missing or invalid
key with `401`.

#### Scenario: Missing API key

- **WHEN** a request to a protected endpoint is made without the API key header
- **THEN** the response is `401` `application/problem+json` and the request is not processed

### Requirement: Idempotent mutating requests

Every mutating endpoint SHALL require an `Idempotency-Key` header and use it to deduplicate:
replay the stored response for a completed key, return `409` for an in-flight key, and `422` for
a reused key with a different payload.

#### Scenario: Replaying a completed request

- **WHEN** the same `POST` is sent twice with the same idempotency key and payload
- **THEN** the second response is the stored response of the first and the state changes only once

#### Scenario: Reusing a key with a different payload

- **WHEN** a `POST` reuses an idempotency key with a different body
- **THEN** the response is `422` `application/problem+json`

### Requirement: Errors are RFC 9457 problem+json

The API SHALL return all errors as `application/problem+json` per RFC 9457
(`type`, `title`, `status`, `detail`; an `errors` member listing field violations for validation
failures), mapping each throwable to an HTTP status, and without leaking internal details on `500`.

#### Scenario: A not-found error

- **WHEN** a request targets a resource that does not exist
- **THEN** the response is `404` with an `application/problem+json` body carrying `type`, `title`, and `status`

#### Scenario: An unexpected error does not leak internals

- **WHEN** an unexpected exception is raised while handling a request
- **THEN** the response is `500` `application/problem+json` with a generic `detail` and no stack trace or internal detail

### Requirement: Requests are validated

The API SHALL validate request bodies and reject invalid ones with `422` `application/problem+json`
describing the offending fields. Amounts are integer minor units with a currency; never floats.

#### Scenario: Invalid deposit body

- **WHEN** `POST /api/accounts/{id}/deposits` is called with a missing or non-positive amount
- **THEN** the response is `422` `application/problem+json` with the offending field in the `errors` member

### Requirement: A served OpenAPI 3.1 contract generated from the controllers

The API SHALL serve an OpenAPI 3.1 document at `GET /api/openapi.{format}` (`json`/`yaml`),
generated by reflection over the invokable `Action` controllers (routes, request DTOs, response
DTOs, and the OpenAPI attributes), describing every endpoint and its schemas with an API-key
security scheme. The document SHALL be well-formed OpenAPI 3.1 and stay in sync with the
controllers because it is derived from them.

#### Scenario: The OpenAPI document is served

- **WHEN** `GET /api/openapi.json` is requested
- **THEN** a well-formed OpenAPI 3.1 document is returned with every endpoint's path, operation, component schemas, and the API-key security scheme

#### Scenario: Response shapes are verified per endpoint

- **WHEN** each endpoint is exercised in its functional test
- **THEN** the actual response body matches the documented shape (fields and types) for that endpoint

### Requirement: Read-only event streams per aggregate

The API SHALL expose the recorded event stream of an account via `GET /api/accounts/{id}/events`
and of a transfer via `GET /api/transfers/{id}/events`, returning for each event its global
position, stream version, event type, schema version, payload, correlation and causation ids, and
occurred-at timestamp. The endpoints SHALL be strictly read-only, require the API key like the rest
of the API, and return `404` problem+json for an unknown stream.

#### Scenario: An account's history is readable

- **WHEN** `GET /api/accounts/{id}/events` is called for an account that was opened and received a deposit
- **THEN** the response lists the account-opened and funds-deposited events in stream order with their metadata

#### Scenario: A transfer's saga trail is readable

- **WHEN** `GET /api/transfers/{id}/events` is called for a completed transfer
- **THEN** the response lists the initiated, held, posted, and completed events in order

#### Scenario: Unknown streams are 404

- **WHEN** the events of a nonexistent id are requested
- **THEN** the response is `404` `application/problem+json`

